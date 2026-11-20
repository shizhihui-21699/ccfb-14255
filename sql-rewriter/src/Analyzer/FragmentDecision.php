<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

use PhpParser\Node\Expr;

/**
 * The rewrite decision for one dynamic fragment.
 */
final class FragmentDecision
{
    public const BIND_VALUE      = 'bind-value';
    public const BIND_IN_LIST    = 'bind-in-list';
    public const CHECK_IDENT     = 'check-identifier';
    public const CHECK_ENUM      = 'check-enum';
    public const REJECT          = 'reject';

    public function __construct(
        public readonly string $action,
        public readonly Expr $expr,
        public readonly Taint $taint,
        public readonly SegmentContext $context,
        public readonly string $detail,
        /** runtime helper argument for identifiers/enums */
        public readonly ?string $hint = null,
        /** for STRING_LITERAL positions: literal text glued around the value */
        public readonly ?string $literalPrefix = null,
        public readonly ?string $literalSuffix = null,
        /** when set, this binding is only included if the condition is truthy */
        public readonly ?Expr $condition = null,
    ) {}
}
