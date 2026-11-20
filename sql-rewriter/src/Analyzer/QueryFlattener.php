<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use SqlRewriter\Config;

/**
 * Rebuilds the ordered list of literal/dynamic segments that make up the
 * SQL expression reaching a sink, following data flow through:
 *
 *  - string concatenation chains and interpolation
 *  - local variables (and their assignment history) and array elements
 *  - sprintf / implode / join
 *  - ternary / coalesce / match arms (same literal shape required)
 *  - user-defined helper functions (via FunctionSummaries inlining)
 *
 * Anything that could change the literal SQL shape in a way the static
 * analysis cannot see raises ReconstructException -> the sink is skipped.
 */
final class QueryFlattener
{
    /** Nodes currently being expanded (recursion guard). */
    private \SplObjectStorage $expanding;

    public function __construct(
        private readonly Config $config,
        private readonly FunctionSummaries $summaries,
    ) {
        $this->expanding = new \SplObjectStorage();
    }

    /**
     * @return list<QuerySegment>
     */
    public function flatten(Expr $expr, TaintTracker $tracker): array
    {
        $segments = $this->expand($expr, $tracker);
        return $this->normalize($segments);
    }

    private function buildHasOptional(SqlBuildState $build): bool
    {
        foreach ($build->steps() as $step) {
            if ($step instanceof OptionalStep) {
                return true;
            }
        }
        return false;
    }

    /**
     * Convert a structural SQL build history into query segments.
     *
     * @return list<QuerySegment>
     */
    private function segmentsFromBuild(SqlBuildState $build): array
    {
        $out = [];
        foreach ($build->steps() as $step) {
            if ($step instanceof LiteralStep) {
                $out[] = QuerySegment::literal($step->text);
            } elseif ($step instanceof ValueStep) {
                $out[] = QuerySegment::dynamic($step->expr, $step->taint);
            } elseif ($step instanceof InListStep) {
                $out[] = QuerySegment::inList($step->expr, $step->taint, $step->glue);
            } elseif ($step instanceof OptionalStep) {
                $inner = [];
                foreach ($step->inner as $sub) {
                    if ($sub instanceof LiteralStep) {
                        $inner[] = QuerySegment::literal($sub->text);
                    } elseif ($sub instanceof ValueStep) {
                        $inner[] = QuerySegment::dynamic($sub->expr, $sub->taint);
                    } elseif ($sub instanceof InListStep) {
                        $inner[] = QuerySegment::inList($sub->expr, $sub->taint, $sub->glue);
                    }
                }
                $out[] = QuerySegment::optional($step->condition, $inner);
            }
        }
        return $this->normalize($out);
    }

    /**
     * @return list<QuerySegment>
     */
    private function expand(Expr $expr, TaintTracker $tracker, bool $allowBuildState = true): array
    {
        if (isset($this->expanding[$expr])) {
            throw new ReconstructException('skip-reconstruct-failed', 'recursive expression expansion');
        }
        $this->expanding[$expr] = true;
        try {
            return $this->doExpand($expr, $tracker, $allowBuildState);
        } finally {
            unset($this->expanding[$expr]);
        }
    }

    private function doExpand(Expr $expr, TaintTracker $tracker, bool $allowBuildState = true): array
    {
        // follow assignments: $sql = "..."; ... query($sql)
        if ($expr instanceof Expr\Variable || $expr instanceof Expr\ArrayDimFetch
            || $expr instanceof Expr\PropertyFetch) {
            // Structural build history only takes over for the top-level SQL
            // variable. When we are recursively expanding a value that happens
            // to be a variable ($orderColumn inside a concat), its build state
            // is irrelevant — it is a bindable value, not a statement.
            if ($allowBuildState) {
                $build = $tracker->buildState($expr);
                if ($build !== null && !$build->isEmpty() && $this->buildHasOptional($build)) {
                    return $this->segmentsFromBuild($build);
                }
            }
            $assigned = $tracker->assignedExpression($expr);
            if ($assigned !== null && $assigned !== $expr) {
                return $this->expand($assigned, $tracker, false);
            }
            if ($tracker->isDiverged($expr)) {
                $name = $expr instanceof Expr\Variable && is_string($expr->name) ? '$' . $expr->name : '<expr>';
                throw new ReconstructException('skip-reconstruct-failed',
                    "SQL variable {$name} is branch-merged from different SQL shapes; "
                    . 'no single reconstructable definition');
            }
            // Function parameter, superglobal read or a value with no tracked
            // definition: a legitimate dynamic *value*, not a SQL fragment.
            return [QuerySegment::dynamic($expr, $tracker->factFor($expr))];
        }

        if ($expr instanceof Node\Scalar\String_) {
            return [QuerySegment::literal($expr->value, $expr->getLine())];
        }
        if ($expr instanceof Node\Scalar\LNumber || $expr instanceof Node\Scalar\DNumber) {
            return [QuerySegment::literal((string) $expr->value, $expr->getLine())];
        }

        if ($expr instanceof Expr\BinaryOp\Concat) {
            return array_merge(
                $this->expand($expr->left, $tracker, $allowBuildState),
                $this->expand($expr->right, $tracker, $allowBuildState),
            );
        }

        if ($expr instanceof Node\Scalar\Encapsed) {
            $out = [];
            foreach ($expr->parts as $part) {
                if ($part instanceof Node\Scalar\EncapsedStringPart) {
                    $out[] = QuerySegment::literal($part->value, $part->getLine());
                } else {
                    $out = array_merge($out, $this->expand($part, $tracker));
                }
            }
            return $out;
        }

        if ($expr instanceof Expr\Ternary) {
            $then = $expr->if ?? $expr->cond;
            return $this->expandBranches([$then, $expr->else], $tracker, [
                'mode' => BranchChoiceExpr::MODE_TERNARY,
                'condition' => $expr->cond,
            ]);
        }
        if ($expr instanceof Expr\BinaryOp\Coalesce) {
            return $this->expandBranches([$expr->left, $expr->right], $tracker, [
                'mode' => BranchChoiceExpr::MODE_COALESCE,
            ]);
        }
        if ($expr instanceof Expr\Match_) {
            $arms = array_map(fn(Expr\MatchArm $arm) => $arm->body, $expr->arms);
            $armConds = array_map(fn(Expr\MatchArm $arm) => $arm->conds, $expr->arms);
            return $this->expandBranches($arms, $tracker, [
                'mode' => BranchChoiceExpr::MODE_MATCH,
                'subject' => $expr->cond,
                'armConds' => $armConds,
            ]);
        }

        if ($expr instanceof Expr\FuncCall || $expr instanceof Expr\StaticCall) {
            $name = $this->callName($expr);
            if ($name !== null && $this->summaries->has($name)) {
                return $this->summaries->inline($name, $expr->getArgs());
            }
            if ($name !== null && strtolower($name) === 'sprintf') {
                return $this->expandSprintf($expr, $tracker);
            }
            if ($name !== null && in_array(strtolower($name), ['implode', 'join'], true)) {
                return $this->expandImplode($expr, $tracker);
            }
            // configured IN-list helper e.g. legacy_int_list($ids)
            if ($name !== null && isset($this->config->inListHelpers[strtolower($name)])) {
                $spec = $this->config->inListHelpers[strtolower($name)];
                $args = $expr->getArgs();
                $target = $args[$spec['valueArg']]->value ?? null;
                if ($target === null) {
                    throw new ReconstructException('skip-unsupported-expression', "{$name}() missing value argument");
                }
                return [QuerySegment::inList($target, $tracker->factFor($target), $spec['glue'], $expr->getLine())];
            }
            if ($name !== null && $this->config->isEscapeHelper($name)) {
                $args = $expr->getArgs();
                $pos = $this->config->escapeArgPosition($name) ?? 0;
                $target = $args[$pos]->value ?? null;
                if ($target === null) {
                    throw new ReconstructException('skip-unsupported-expression', "{$name}() missing argument");
                }
                // escaped value in a value position -> plain parameter binding
                return [QuerySegment::dynamic($target, $tracker->factFor($target), $expr->getLine())];
            }
            if ($name !== null && Sanitizers::isSanitizer($name)) {
                return [QuerySegment::dynamic($expr, Taint::safe('sanitizer'), $expr->getLine())];
            }
            return [QuerySegment::dynamic($expr, $tracker->factFor($expr), $expr->getLine())];
        }

        if ($expr instanceof Expr\Cast\Int_ || $expr instanceof Expr\Cast\Double
            || $expr instanceof Expr\Cast\Bool_) {
            return [QuerySegment::dynamic($expr->expr, Taint::safe('numeric-cast'), $expr->getLine())];
        }

        if ($expr instanceof Expr\UnaryMinus || $expr instanceof Expr\UnaryPlus) {
            $inner = $this->expand($expr->expr, $tracker);
            return array_merge([QuerySegment::literal($expr instanceof Expr\UnaryMinus ? '-' : '+')], $inner);
        }

        if ($expr instanceof Expr\Assign || $expr instanceof Expr\AssignRef) {
            return $this->expand($expr->expr, $tracker);
        }
        if ($expr instanceof Expr\AssignOp\Concat) {
            // $sql .= $rhs expands to (prior definition of $sql) . $rhs.
            // The prior definition is attached during taint analysis, which
            // breaks what would otherwise be an infinite expansion loop.
            $lhs = $expr->getAttribute('rwPriorDef');
            if ($lhs === null) {
                throw new ReconstructException('skip-reconstruct-failed',
                    'compound concatenation on a variable with no prior definition');
            }
            return array_merge(
                $this->expand($lhs, $tracker),
                $this->expand($expr->expr, $tracker),
            );
        }
        if ($expr instanceof Expr\AssignOp) {
            // other compound assignments (+= etc.) cannot build SQL text
            throw new ReconstructException('skip-unsupported-expression',
                'compound assignment ' . $expr->getType() . ' cannot build SQL text');
        }

        if ($expr instanceof Expr\Clone_ || $expr instanceof Expr\Array_ || $expr instanceof Expr\Closure
            || $expr instanceof Expr\ArrowFunction) {
            throw new ReconstructException('skip-unsupported-expression',
                'expression of type ' . $expr->getType() . ' cannot be converted to SQL text');
        }

        // fallback: treat as a single dynamic value
        return [QuerySegment::dynamic($expr, $tracker->factFor($expr), $expr->getLine())];
    }

    /**
     * All branches must produce the same sequence of literal vs dynamic
     * slots; only the expressions behind the dynamic slots may differ.
     *
     * @param list<Expr> $branches
     * @param array{mode:string, condition?:?Expr, subject?:?Expr, armConds?:list<?list<Expr>>} $origin
     * @return list<QuerySegment>
     */
    private function expandBranches(array $branches, TaintTracker $tracker, array $origin): array
    {
        $expanded = array_map(fn(Expr $e) => $this->expand($e, $tracker), $branches);
        $reference = $expanded[0];

        // Compatibility rule: branches must align slot-by-slot. Two slots are
        // compatible when both are literal with identical text, or both are
        // single fragments (literal OR dynamic) occupying one value slot —
        // binding treats a literal and a variable identically, so a branch
        // that returns 'viewer' against one that returns $role is sound.
        foreach (array_slice($expanded, 1) as $candidate) {
            if (count($candidate) !== count($reference)) {
                throw new ReconstructException('skip-reconstruct-failed',
                    "{$origin['mode']} branches build SQL with a different number of fragments");
            }
            foreach ($reference as $i => $refSeg) {
                $candSeg = $candidate[$i];
                // Two single-fragment slots are compatible even when their
                // literal text differs: the slot becomes a bound parameter
                // and each branch supplies its own value expression.
                $refSingle = $refSeg->kind !== QuerySegment::IN_LIST;
                $candSingle = $candSeg->kind !== QuerySegment::IN_LIST;
                if (!$refSingle || !$candSingle) {
                    throw new ReconstructException('skip-reconstruct-failed',
                        "{$origin['mode']} branches disagree on IN-list layout");
                }
            }
        }

        $out = [];
        foreach ($reference as $i => $seg) {
            $allLiteral = array_all($expanded, fn($shape) => $shape[$i]->kind === QuerySegment::LITERAL);
            $allSameText = $allLiteral && array_all(
                $expanded,
                fn($shape) => $shape[$i]->text === $seg->text,
            );
            if ($allSameText) {
                $out[] = $seg; // identical literal across every branch -> keep literal
                continue;
            }
            // Bind the slot; literal branches contribute literal value exprs.
            $opts = [];
            $taints = [];
            foreach ($expanded as $shape) {
                $slot = $shape[$i];
                if ($slot->kind === QuerySegment::LITERAL) {
                    $opts[] = new Node\Scalar\String_((string) $slot->text);
                    $taints[] = Taint::safe('branch-literal');
                } else {
                    $opts[] = $slot->expr;
                    $taints[] = $slot->taint ?? Taint::safe();
                }
            }
            $taint = array_reduce($taints, Taint::join(...), Taint::safe());
            $out[] = QuerySegment::dynamic(new BranchChoiceExpr($opts, $origin), $taint, $seg->line);
        }
        return $out;
    }

    /**
     * @return list<QuerySegment>
     */
    private function expandSprintf(Expr\FuncCall $call, TaintTracker $tracker): array
    {
        $args = $call->getArgs();
        if ($args === [] || !($args[0]->value instanceof Node\Scalar\String_)) {
            throw new ReconstructException('skip-sprintf-format', 'sprintf format string is not a literal');
        }
        $format = $args[0]->value->value;
        $argValues = array_map(fn(Arg $a) => $a->value, array_slice($args, 1));

        $out = [];
        $cursor = 0;
        $argIndex = 0;
        $len = strlen($format);
        while ($cursor < $len) {
            $pct = strpos($format, '%', $cursor);
            if ($pct === false) {
                $out[] = QuerySegment::literal(substr($format, $cursor), $call->getLine());
                break;
            }
            if ($pct > $cursor) {
                $out[] = QuerySegment::literal(substr($format, $cursor, $pct - $cursor), $call->getLine());
            }
            // Hand-rolled spec scanner: %[argnum$][flags][width][.precision]specifier
            $spec = $this->scanSprintfSpec($format, $pct, $specLen);
            if ($spec === null) {
                throw new ReconstructException('skip-sprintf-format', 'malformed sprintf conversion specification');
            }
            if ($spec === '%%') {
                $out[] = QuerySegment::literal('%', $call->getLine());
                $cursor = $pct + $specLen;
                continue;
            }
            $explicitIndex = null;
            if (preg_match('/^%(\d+)\$/', $spec, $im)) {
                $explicitIndex = ((int) $im[1]) - 1;
            }
            $idx = $explicitIndex ?? $argIndex++;
            if (!isset($argValues[$idx])) {
                throw new ReconstructException('skip-sprintf-format', 'sprintf argument missing for placeholder');
            }
            $argExpr = $argValues[$idx];
            $specifier = substr($spec, -1);
            // numeric conversions produce unquoted numbers: treat as dynamic value
            if (in_array($specifier, ['d', 'u', 'f', 'F', 'e', 'E', 'g', 'G', 'o', 'x', 'X', 'b', 'c'], true)) {
                $out[] = QuerySegment::dynamic($argExpr, Taint::safe('sprintf-' . $specifier), $call->getLine());
            } elseif ($specifier === 's') {
                $out = array_merge($out, $this->expand($argExpr, $tracker));
            } else {
                throw new ReconstructException('skip-sprintf-format', "unsupported sprintf specifier %{$specifier}");
            }
            $cursor = $pct + $specLen;
        }
        return $out;
    }

    /**
     * Scan one sprintf conversion specification starting at $start (the '%').
     * Returns the spec text, or null when malformed. $outLen receives its length.
     */
    private function scanSprintfSpec(string $format, int $start, ?int &$outLen): ?string
    {
        $len = strlen($format);
        $j = $start + 1;
        if ($j >= $len) {
            return null;
        }
        if ($format[$j] === '%') {
            $outLen = 2;
            return '%%';
        }
        // positional argnum$
        if (preg_match('/\G(\d+)\$/', $format, $m, 0, $j)) {
            $j += strlen($m[0]);
        }
        // flags
        while ($j < $len && strpos("+-0' #", $format[$j]) !== false) {
            $j++;
        }
        // width
        if ($j < $len && $format[$j] === '*') {
            $j++;
        } else {
            while ($j < $len && ctype_digit($format[$j])) {
                $j++;
            }
        }
        // precision
        if ($j < $len && $format[$j] === '.') {
            $j++;
            if ($j < $len && $format[$j] === '*') {
                $j++;
            } else {
                while ($j < $len && ctype_digit($format[$j])) {
                    $j++;
                }
            }
        }
        if ($j >= $len) {
            return null;
        }
        $specifier = $format[$j];
        if (!str_contains('bcdeEufFgGosxX', $specifier)) {
            return null;
        }
        $outLen = $j - $start + 1;
        return substr($format, $start, $outLen);
    }

    /**
     * @return list<QuerySegment>
     */
    private function expandImplode(Expr\FuncCall $call, TaintTracker $tracker): array
    {
        $args = $call->getArgs();
        if (count($args) === 1) {
            // implode(array) — empty glue
            $glue = '';
            $arrayExpr = $args[0]->value;
        } elseif (count($args) === 2) {
            // Both implode($glue, $array) and the historical
            // implode($array, $glue) are legal in PHP; detect which is which.
            $first = $args[0]->value;
            $second = $args[1]->value;
            $firstGlue = $tracker->staticValue($first);
            if ($firstGlue !== null) {
                $glue = $firstGlue;
                $arrayExpr = $second;
            } else {
                $secondGlue = $tracker->staticValue($second);
                if ($secondGlue === null) {
                    throw new ReconstructException('skip-unsupported-expression',
                        'implode glue is not a static string');
                }
                $glue = $secondGlue;
                $arrayExpr = $first;
            }
        } else {
            throw new ReconstructException('skip-unsupported-expression', 'implode with wrong argument count');
        }
        return [QuerySegment::inList($arrayExpr, $tracker->factFor($arrayExpr), $glue, $call->getLine())];
    }

    /**
     * Merge adjacent literals, strip empty ones.
     *
     * @param list<QuerySegment> $segments
     * @return list<QuerySegment>
     */
    private function normalize(array $segments): array
    {
        $out = [];
        foreach ($segments as $seg) {
            if ($seg->kind === QuerySegment::LITERAL && $seg->text === '') {
                continue;
            }
            $last = $out[count($out) - 1] ?? null;
            if ($last !== null && $last->kind === QuerySegment::LITERAL && $seg->kind === QuerySegment::LITERAL) {
                $out[array_key_last($out)] = QuerySegment::literal($last->text . $seg->text, $seg->line);
                continue;
            }
            $out[] = $seg;
        }
        return $out;
    }

    private function callName(Expr\CallLike $call): ?string
    {
        if ($call instanceof Expr\FuncCall && $call->name instanceof Node\Name) {
            return $call->name->toString();
        }
        if ($call instanceof Expr\StaticCall && $call->name instanceof Node\Identifier) {
            $class = $call->class instanceof Node\Name ? $call->class->toString() : null;
            return $class !== null ? $class . '::' . $call->name->name : null;
        }
        return null;
    }
}
