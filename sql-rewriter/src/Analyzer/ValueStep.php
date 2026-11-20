<?php
declare(strict_types=1);
namespace SqlRewriter\Analyzer;

use PhpParser\Node\Expr;

final class ValueStep extends BuildStep
{
    public function __construct(
        public readonly Expr $expr,
        public readonly Taint $taint,
    ) {}

    public function equals(BuildStep $other): bool
    {
        return $other instanceof self && $other->expr === $this->expr;
    }
}
