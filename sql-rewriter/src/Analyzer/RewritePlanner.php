<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

use PhpParser\Node\Expr;
use SqlRewriter\Config;

/**
 * Turns (reconstructed segments + SQL structural contexts) into a concrete
 * rewrite plan, or produces a refusal with a precise reason code.
 *
 * Soundness rule: the planner refuses whenever it cannot prove that the
 * emitted query has identical semantics for every possible input.
 */
final class RewritePlanner
{
    public function __construct(private readonly Config $config) {}

    /**
     * @param list<QuerySegment> $segments
     * @return array{template:string, decisions:list<FragmentDecision>, parts:list<PlanPart>, shape:string, hasConditions:bool}
     *
     * @throws ReconstructException
     */
    public function plan(array $segments): array
    {
        // Linearize optional groups, carrying each segment's guard condition.
        /** @var list<array{seg:QuerySegment, condition:?Expr}> $flat */
        $flat = [];
        foreach ($segments as $seg) {
            if ($seg->kind === QuerySegment::OPTIONAL) {
                foreach ($seg->inner as $inner) {
                    $flat[] = ['seg' => $inner, 'condition' => $seg->condition];
                }
            } else {
                $flat[] = ['seg' => $seg, 'condition' => null];
            }
        }

        // Marker SQL over the whole statement so structural context is computed once.
        $markerSql = '';
        $dynamicCount = 0;
        foreach ($flat as $entry) {
            if ($entry['seg']->kind === QuerySegment::LITERAL) {
                $markerSql .= $entry['seg']->text;
            } else {
                $markerSql .= '__RWM' . $dynamicCount . '__';
                $dynamicCount++;
            }
        }

        $structure = new SqlStructure();
        $contexts = $structure->analyze($markerSql, $dynamicCount);

        $decisions = [];
        $parts = [];
        $currentSql = '';
        $currentCond = null;
        $currentIndices = [];
        $hasConditions = false;
        $dynIndex = 0;
        // When the next dynamic slot sits inside a quoted string literal,
        // the opening quote already appended to $currentSql must be removed
        // because the bound parameter carries the full (prefix+value+suffix)
        // string and must not itself be quoted.
        $pendingStringOpen = false;
        $pendingStringSuffix = '';

        $flush = function () use (&$parts, &$currentSql, &$currentCond, &$currentIndices) {
            if ($currentSql !== '' || $currentIndices !== []) {
                $parts[] = new PlanPart($currentSql, $currentCond, $currentIndices);
            }
            $currentSql = '';
            $currentIndices = [];
        };

        foreach ($flat as $entry) {
            $seg = $entry['seg'];
            $condition = $entry['condition'];
            if ($condition !== null) {
                $hasConditions = true;
            }
            if ($condition !== $currentCond) {
                $flush();
                $currentCond = $condition;
            }

            if ($seg->kind === QuerySegment::LITERAL) {
                $text = $seg->text;
                if ($pendingStringOpen) {
                    // This literal follows a marker that was inside a quoted
                    // string. Drop the closing quote and any suffix text that
                    // the binding already carries via literalSuffix, so the
                    // wildcard/affix is not duplicated in SQL.
                    $suffix = $pendingStringSuffix;
                    if ($suffix !== '' && str_starts_with($text, $suffix)) {
                        $text = substr($text, strlen($suffix));
                    }
                    if (str_starts_with($text, "'")) {
                        $text = substr($text, 1);
                    } elseif (str_ends_with($text, "'")) {
                        $text = substr($text, 0, -1);
                    }
                    $pendingStringOpen = false;
                    $pendingStringSuffix = '';
                }
                $currentSql .= $text;
                continue;
            }

            $context = $contexts[$dynIndex]
                ?? throw new ReconstructException('skip-structure-unknown', 'lost structural context');
            $decision = $this->decide($seg, $context);
            if ($condition !== null) {
                $decision = new FragmentDecision(
                    $decision->action,
                    $decision->expr,
                    $decision->taint,
                    $decision->context,
                    $decision->detail,
                    $decision->hint,
                    $decision->literalPrefix,
                    $decision->literalSuffix,
                    $condition,
                );
            }

            // For a marker inside a quoted string, remove the opening quote AND
            // any prefix text (e.g. a leading %) from the SQL: both are carried
            // by the bound value through literalPrefix, and the suffix/quote are
            // handled when the next literal segment is flushed.
            if ($context->kind === SegmentContext::STRING_LITERAL) {
                $prefix = $context->literalPrefix ?? '';
                if ($prefix !== '' && str_ends_with($currentSql, $prefix)) {
                    $currentSql = substr($currentSql, 0, -strlen($prefix));
                }
                $quotePos = strrpos($currentSql, "'");
                if ($quotePos !== false) {
                    $currentSql = substr($currentSql, 0, $quotePos) . substr($currentSql, $quotePos + 1);
                }
                $pendingStringOpen = true;
                $pendingStringSuffix = $context->literalSuffix ?? '';
            }

            $globalIndex = count($decisions);
            $decisions[] = $decision;
            $currentIndices[] = $globalIndex;
            $currentSql .= match ($decision->action) {
                FragmentDecision::BIND_VALUE   => '__RWV' . $globalIndex . '__',
                FragmentDecision::BIND_IN_LIST => '__RWL' . $globalIndex . '__',
                FragmentDecision::CHECK_IDENT  => '__RWI' . $globalIndex . '__',
                FragmentDecision::CHECK_ENUM   => '__RWE' . $globalIndex . '__',
                default => throw new ReconstructException('skip-unsupported-expression', 'unemittable decision'),
            };
            $dynIndex++;
        }
        $flush();

        $template = implode('', array_map(fn(PlanPart $p) => $p->sql, $parts));

        return [
            'template' => $template,
            'decisions' => $decisions,
            'parts' => $parts,
            'hasConditions' => $hasConditions,
            'shape' => $this->readableShape($markerSql, $contexts),
        ];
    }

    private function decide(QuerySegment $seg, SegmentContext $ctx): FragmentDecision
    {
        $expr = $seg->expr;
        \assert($expr !== null);

        switch ($ctx->kind) {
            case SegmentContext::VALUE:
            case SegmentContext::STRING_LITERAL:
                // Even safe literals get bound: one uniform execution path.
                return new FragmentDecision(
                    FragmentDecision::BIND_VALUE,
                    $expr,
                    $seg->taint ?? Taint::safe(),
                    $ctx,
                    $ctx->kind === SegmentContext::STRING_LITERAL
                        ? 'value embedded in a quoted literal; bind and reattach surrounding text'
                        : 'value position; bound as a prepared-statement parameter',
                    $ctx->hint,
                    $ctx->literalPrefix,
                    $ctx->literalSuffix,
                );

            case SegmentContext::IN_LIST:
                return new FragmentDecision(
                    FragmentDecision::BIND_IN_LIST,
                    $expr,
                    $seg->taint ?? Taint::safe(),
                    $ctx,
                    'IN(...) list; placeholder count generated at runtime from the array',
                    $seg->glue,
                );

            case SegmentContext::IDENTIFIER:
                if (!$this->identifierIsWhitelisted($seg)) {
                    throw new ReconstructException(
                        IssueReason::IDENTIFIER_NO_WHITELIST,
                        $this->identifierRejectionDetail($seg, $ctx),
                    );
                }
                return new FragmentDecision(
                    FragmentDecision::CHECK_IDENT,
                    $expr,
                    $seg->taint ?? Taint::safe(),
                    $ctx,
                    'identifier position; runtime whitelist check against schema + static literal guard',
                    $ctx->hint,
                );

            case SegmentContext::KEYWORD:
                $enum = $ctx->hint !== null ? ($this->config->enumFor($ctx->hint) ?? null) : null;
                if ($enum === null) {
                    throw new ReconstructException(
                        IssueReason::STRUCTURE_UNKNOWN,
                        "keyword position has no configured enum (hint={$ctx->hint})",
                    );
                }
                if (!$this->keywordLiteralAllowed($seg, $enum)) {
                    throw new ReconstructException(
                        IssueReason::IDENTIFIER_NO_WHITELIST,
                        'dynamic keyword cannot be statically proven to belong to: ' . implode(', ', $enum),
                    );
                }
                return new FragmentDecision(
                    FragmentDecision::CHECK_ENUM,
                    $expr,
                    $seg->taint ?? Taint::safe(),
                    $ctx,
                    'keyword position; runtime enum check (' . implode('|', $enum) . ')',
                    $ctx->hint,
                );

            case SegmentContext::CLAUSE_FRAGMENT:
                throw new ReconstructException(
                    IssueReason::OPAQUE_FRAGMENT,
                    'a whole boolean/structural SQL fragment is concatenated; its shape is not a bindable value',
                );

            case SegmentContext::UNKNOWN:
            default:
                throw new ReconstructException(
                    IssueReason::STRUCTURE_UNKNOWN,
                    'syntactic position cannot be classified: ' . $ctx->detail,
                );
        }
    }

    /**
     * An identifier is rewriteable only when BOTH hold:
     *  1. every statically visible literal value is a known identifier
     *     (catches the in_array/switch whitelist idiom at analysis time);
     *  2. a runtime guard is emitted so non-literal input is still checked
     *     against the schema-derived whitelist.
     */
    private function identifierIsWhitelisted(QuerySegment $seg): bool
    {
        foreach ($this->literalValues($seg) as $value) {
            if (!$this->config->isKnownIdentifier($value)) {
                return false;
            }
        }
        return true;
    }

    private function identifierRejectionDetail(QuerySegment $seg, SegmentContext $ctx): string
    {
        $literals = $this->literalValues($seg);
        if ($literals === []) {
            return "dynamic {$ctx->hint} with no statically visible allowed values; "
                . 'extend the schema whitelist or add an explicit in_array() guard';
        }
        $bad = array_filter($literals, fn($v) => !$this->config->isKnownIdentifier($v));
        return 'identifier value(s) absent from schema whitelist: ' . implode(', ', $bad);
    }

    private function keywordLiteralAllowed(QuerySegment $seg, array $enum): bool
    {
        foreach ($this->literalValues($seg) as $value) {
            if (!in_array(strtoupper($value), array_map('strtoupper', $enum), true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<string>
     */
    private function literalValues(QuerySegment $seg): array
    {
        $expr = $seg->expr;
        if ($expr === null) {
            return [];
        }
        $values = [];
        $collect = function (Expr $e) use (&$values, &$collect) {
            if ($e instanceof \PhpParser\Node\Scalar\String_
                || $e instanceof \PhpParser\Node\Scalar\LNumber) {
                $values[] = (string) $e->value;
                return;
            }
            if ($e instanceof BranchChoiceExpr) {
                foreach ($e->choices as $choice) {
                    $collect($choice);
                }
                return;
            }
            if ($e instanceof Expr\UnaryMinus && $e->expr instanceof \PhpParser\Node\Scalar\LNumber) {
                $values[] = '-' . $e->expr->value;
                return;
            }
            if ($e instanceof Expr\Cast\String_ || $e instanceof Expr\Cast\Int_) {
                $collect($e->expr);
            }
        };
        $collect($expr);
        return $values;
    }

    private function readableShape(string $markerSql, array $contexts): string
    {
        foreach ($contexts as $i => $ctx) {
            $label = match ($ctx->kind) {
                SegmentContext::VALUE => '?value',
                SegmentContext::STRING_LITERAL => '?str',
                SegmentContext::IN_LIST => '?(...)',
                SegmentContext::IDENTIFIER => '?ident',
                SegmentContext::KEYWORD => '?kw',
                default => '?UNKNOWN',
            };
            $markerSql = str_replace("__RWM{$i}__", $label, $markerSql);
        }
        return preg_replace('/\s+/', ' ', trim($markerSql));
    }
}
