<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

use PhpParser\Node\Expr;

/**
 * Synthetic expression holding the parallel alternatives produced by a
 * ternary / coalesce / match where every branch has the same literal SQL
 * shape. The Emitter rebuilds the original PHP branching expression from
 * the stored mode + components, so runtime value selection is identical.
 */
final class BranchChoiceExpr extends Expr
{
    public const MODE_TERNARY  = 'ternary';
    public const MODE_COALESCE = 'coalesce';
    public const MODE_MATCH    = 'match';

    /**
     * @param list<Expr> $choices per-branch value expressions (same order)
     * @param array{mode:string, condition?:?Expr, subject?:?Expr, armConds?:list<?list<Expr>>} $origin
     */
    public function __construct(
        public readonly array $choices,
        public readonly array $origin,
    ) {
        parent::__construct();
    }

    public function getSubNodeNames(): array
    {
        return ['choices', 'origin'];
    }

    public function getType(): string
    {
        return 'RwBranchChoice';
    }
}
