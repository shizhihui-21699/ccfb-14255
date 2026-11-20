<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

use PhpParser\Node\Expr;

/**
 * One contiguous piece of a rewritten statement.
 *
 * A plan without conditional appends consists of a single part whose
 * condition is null. When the original code appended SQL inside an if,
 * each such append becomes a part carrying that if's condition; the
 * runtime includes the part only when the condition is truthy.
 *
 * Bindings are referenced by their GLOBAL index into the plan's decision
 * list, so positional parameter order stays identical to the original
 * concatenation order.
 */
final class PlanPart
{
    /**
     * @param list<int> $bindingIndices global decision indices used by $sql
     */
    public function __construct(
        public readonly string $sql,
        public readonly ?Expr $condition,
        public readonly array $bindingIndices,
    ) {}
}
