<?php
declare(strict_types=1);
namespace SqlRewriter\Analyzer;

use PhpParser\Node\Expr;

/**
 * A group of steps present only when $condition evaluates truthy.
 * Mirrors an `if (...) $sql .= ...;` append.
 */
final class OptionalStep extends BuildStep
{
    /**
     * @param list<BuildStep> $inner
     */
    public function __construct(
        public readonly ?Expr $condition,
        public readonly array $inner,
    ) {}

    public function equals(BuildStep $other): bool
    {
        return $other === $this;
    }
}
