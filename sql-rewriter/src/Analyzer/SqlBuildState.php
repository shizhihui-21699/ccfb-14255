<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

use PhpParser\Node\Expr;

/**
 * Structural construction history of one SQL-bearing variable.
 *
 * Instead of trying to represent `$sql = BASE; if (c) $sql .= X; if (d)
 * $sql .= Y;` as a single expression tree (which cannot express mutually
 * exclusive appends that merge back), the tracker records an ordered list
 * of build steps:
 *
 *   - LiteralStep: static SQL text
 *   - ValueStep:   a dynamic value expression
 *   - OptionalStep: a group of steps present only when a runtime condition
 *
 * The flattener turns this directly into literal/dynamic QuerySegments,
 * emitting a PHP conditional around the bindings for optional steps, so
 * the executed SQL matches the original control flow exactly.
 */
final class SqlBuildState
{
    /** @var list<BuildStep> */
    private array $steps = [];

    public function appendStep(BuildStep $step): void
    {
        $this->steps[] = $step;
    }

    /** @return list<BuildStep> */
    public function steps(): array
    {
        return $this->steps;
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }

    /**
     * Merge two construction histories at a branch join.
     * The shared prefix is preserved; divergent tails become an optional
     * group per branch. Returns null when the two histories are structurally
     * incompatible (e.g. different initial SQL text).
     */
    public static function merge(self $a, self $b, ?Expr $condition): ?self
    {
        // For the append pattern both histories share the same steps up to
        // the branch point; b's extra tail (if any) becomes conditional.
        $common = count($a->steps);
        $sharedPrefix = array_slice($b->steps, 0, $common);
        if (!self::stepsEqual($a->steps, $sharedPrefix)) {
            return null;
        }
        $out = new self();
        foreach ($a->steps as $step) {
            $out->appendStep($step);
        }
        $tail = array_slice($b->steps, $common);
        if ($tail !== []) {
            $out->appendStep(new OptionalStep($condition, $tail));
        }
        return $out;
    }

    /**
     * @param list<BuildStep> $x
     * @param list<BuildStep> $y
     */
    private static function stepsEqual(array $x, array $y): bool
    {
        if (count($x) !== count($y)) {
            return false;
        }
        foreach ($x as $i => $step) {
            if (!$step->equals($y[$i])) {
                return false;
            }
        }
        return true;
    }
}
