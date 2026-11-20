<?php
declare(strict_types=1);

namespace SqlRewriter\Rewriter;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use SqlRewriter\Analyzer\BranchChoiceExpr;
use SqlRewriter\Analyzer\FragmentDecision;
use SqlRewriter\Analyzer\QuerySegment;
use SqlRewriter\Config;
use SqlRewriter\Runtime\Binding;

/**
 * Builds the PHP expression tree emitted for a rewritten sink call:
 *  - the SQL template string (with __RW*__ markers)
 *  - the bindings array
 *  - the replacement sink call itself
 */
final class Emitter
{
    public function __construct(private readonly Config $config) {}

    /**
     * Build the replacement call expression for a rewritten sink.
     *
     * Flat legacy sinks are redirected to their SafeDb adapter of the same
     * name (legacy_db_query -> SafeDbCompat::legacy_db_query), preserving
     * their original argument positions for any non-SQL parameters.
     *
     * @param list<FragmentDecision> $decisions
     */
    public function rewriteCall(
        \SqlRewriter\Analyzer\SinkSite $site,
        string $template,
        array $decisions,
    ): Expr\CallLike {
        $templateArg = new Arg($this->templateString($template));
        $bindingsArg = new Arg($this->bindingsArray($decisions));

        if (!$site->isMethodCall) {
            $adapter = $this->config->runtimeNs . '\\SafeDbCompat';
            return new Expr\StaticCall(
                new Name\FullyQualified($adapter),
                new Identifier($site->sinkName),
                array_merge([$templateArg, $bindingsArg], $this->extraArgs($site)),
            );
        }

        // PDO-style: $pdo->query($sql) -> SafeDb::execute($sql, $bindings)
        return $this->staticCall('SafeDb', 'execute', [$templateArg, $bindingsArg]);
    }

    /**
     * Build the replacement call for a plan containing conditional fragments
     * (the original SQL was appended inside if/elseif/else branches).
     *
     * @param list<FragmentDecision>        $decisions
     * @param list<\SqlRewriter\Analyzer\PlanPart> $parts
     */
    public function rewriteFragmentsCall(
        \SqlRewriter\Analyzer\SinkSite $site,
        array $decisions,
        array $parts,
    ): Expr\CallLike {
        $bindingsArg = new Arg($this->bindingsArray($decisions));

        $fragmentItems = [];
        foreach ($parts as $part) {
            $active = $part->condition === null
                ? new Node\Expr\ConstFetch(new Name('null'))
                : $this->printableExpr($part->condition);
            $indexItems = array_map(fn(int $i) => new Node\Scalar\LNumber($i), $part->bindingIndices);
            $fragment = new Expr\New_(
                new Name\FullyQualified($this->config->runtimeNs . '\\Fragment'),
                [
                    new Arg($this->templateString($part->sql)),
                    new Arg($active),
                    new Arg(new Expr\Array_($indexItems)),
                ],
            );
            $fragmentItems[] = new Expr\ArrayItem($fragment);
        }
        $fragmentsArg = new Arg(new Expr\Array_($fragmentItems));

        if (!$site->isMethodCall) {
            $adapter = $this->config->runtimeNs . '\\SafeDbCompat';
            return new Expr\StaticCall(
                new Name\FullyQualified($adapter),
                new Identifier($site->sinkName . '_fragments'),
                array_merge([$bindingsArg, $fragmentsArg], $this->extraArgs($site)),
            );
        }

        return $this->staticCall('SafeDb', 'executeFragments', [$bindingsArg, $fragmentsArg]);
    }

    /**
     * Preserve arguments after the SQL position (e.g. the second argument of
     * legacy_db_execute($sql, $mode)) so the adapter behaves identically.
     *
     * @return list<Arg>
     */
    private function extraArgs(\SqlRewriter\Analyzer\SinkSite $site): array
    {
        $args = $site->call->getArgs();
        return array_slice($args, $site->spec['position'] + 1);
    }

    public function templateString(string $template): Scalar\String_
    {
        return new Scalar\String_($template, ['kind' => Scalar\String_::KIND_SINGLE_QUOTED]);
    }

    /**
     * @param list<FragmentDecision> $decisions
     */
    public function bindingsArray(array $decisions): Expr\Array_
    {
        $items = [];
        foreach ($decisions as $decision) {
            $items[] = new Expr\ArrayItem($this->bindingCall($decision));
        }
        return new Expr\Array_($items);
    }

    private function bindingCall(FragmentDecision $d): Expr
    {
        $valueExpr = $this->printableExpr($d->expr);

        return match ($d->action) {
            FragmentDecision::BIND_VALUE => $this->staticCall('Binding', 'value', $this->valueArgs($d, $valueExpr)),
            FragmentDecision::BIND_IN_LIST => $this->staticCall('Binding', 'inList', [$valueExpr]),
            FragmentDecision::CHECK_IDENT => $this->staticCall('Binding', 'identifier',
                [$valueExpr, $d->hint !== null ? $this->str($d->hint) : $this->str('identifier')]),
            FragmentDecision::CHECK_ENUM => $this->staticCall('Binding', 'enum',
                [$valueExpr, $this->str($d->hint ?? '')]),
            default => throw new \LogicException('cannot emit decision: ' . $d->action),
        };
    }

    /**
     * @return list<Arg>
     */
    private function valueArgs(FragmentDecision $d, Expr $valueExpr): array
    {
        if ($d->context->kind === \SqlRewriter\Analyzer\SegmentContext::STRING_LITERAL) {
            return [
                $valueExpr,
                $this->str($d->literalPrefix ?? ''),
                $this->str($d->literalSuffix ?? ''),
            ];
        }
        return [new Arg($valueExpr)];
    }

    /**
     * Convert analyzer-internal synthetic expressions back into real PHP
     * expressions (branch choices become the original ternary/coalesce/match).
     */
    public function printableExpr(Expr $expr): Expr
    {
        if ($expr instanceof BranchChoiceExpr) {
            $mode = $expr->origin['mode'];
            $choices = array_map(fn(Expr $e) => $this->printableExpr($e), $expr->choices);

            if ($mode === BranchChoiceExpr::MODE_COALESCE && count($choices) === 2) {
                return new Expr\BinaryOp\Coalesce($choices[0], $choices[1]);
            }
            if ($mode === BranchChoiceExpr::MODE_TERNARY && count($choices) === 2) {
                $condition = $expr->origin['condition'] ?? null;
                if (!$condition instanceof Expr) {
                    throw new \LogicException('ternary branch choice lost its condition');
                }
                // Short ternary ($a ?: $b) has condition === choices[0]
                $if = $condition === $expr->choices[0] ? null : $choices[0];
                return new Expr\Ternary($this->printableExpr($condition), $if, $choices[1]);
            }
            if ($mode === BranchChoiceExpr::MODE_MATCH) {
                $subject = $expr->origin['subject'] ?? null;
                $armConds = $expr->origin['armConds'] ?? [];
                if (!$subject instanceof Expr || count($armConds) !== count($choices)) {
                    throw new \LogicException('match branch choice lost its subject/arms');
                }
                $arms = [];
                foreach ($armConds as $i => $conds) {
                    $arms[] = new Expr\MatchArm(
                        $conds === null ? null : array_map(fn(Expr $e) => $this->printableExpr($e), $conds),
                        $choices[$i],
                    );
                }
                return new Expr\Match_($this->printableExpr($subject), $arms);
            }
            throw new \LogicException('cannot reconstruct branch choice mode: ' . $mode);
        }
        return $this->cloneTree($expr);
    }

    private function cloneTree(Expr $expr): Expr
    {
        $clone = clone $expr;
        foreach ($clone->getSubNodeNames() as $name) {
            $sub = $clone->$name;
            if ($sub instanceof Expr) {
                $clone->$name = $this->printableExpr($sub);
            } elseif (is_array($sub)) {
                $clone->$name = array_map(
                    fn($v) => $v instanceof Expr ? $this->printableExpr($v)
                        : ($v instanceof Node ? clone $v : $v),
                    $sub,
                );
            }
        }
        return $clone;
    }

    private function staticCall(string $class, string $method, array $args): Expr\StaticCall
    {
        return new Expr\StaticCall(
            new Name\FullyQualified($this->config->runtimeNs . '\\' . $class),
            new Identifier($method),
            $args,
        );
    }

    private function str(string $value): Scalar\String_
    {
        return new Scalar\String_($value, ['kind' => Scalar\String_::KIND_SINGLE_QUOTED]);
    }
}
