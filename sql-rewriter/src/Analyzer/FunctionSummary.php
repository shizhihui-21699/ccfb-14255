<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use SqlRewriter\Config;

/**
 * A summary of one user-defined function: how its returned SQL is built
 * out of literal text and parameter-indexed slots.
 *
 * A function returning different SQL shapes on different branches, or a
 * function whose return cannot be reconstructed, is marked opaque and
 * forces every caller that passes tainted data into it to be rejected.
 */
final class FunctionSummary
{
    /**
     * @param list<QuerySegment> $segments
     *        dynamic segments carry a placeholder ParamExpr referencing
     *        the 0-based function parameter index
     * @param array<int, Taint> $paramTaints taint fact assumed per parameter
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $opaque,
        public readonly array $segments,
        public readonly array $paramTaints,
        public readonly ?Taint $constantReturnTaint,
        public readonly string $reason = '',
    ) {}

    public static function opaque(string $name, string $reason): self
    {
        return new self($name, true, [], [], null, $reason);
    }
}

/**
 * Artificial expression standing for "parameter $index of the summarized
 * function". It never gets printed; the inliner substitutes the caller's
 * actual argument expression.
 */
final class ParamExpr extends Expr
{
    public function __construct(public int $index, public readonly string $functionName)
    {
        parent::__construct();
    }

    public function getSubNodeNames(): array
    {
        return ['index'];
    }

    public function getType(): string
    {
        return 'RwParam';
    }
}
