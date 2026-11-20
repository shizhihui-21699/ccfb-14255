<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

/**
 * Where a dynamic fragment sits inside a SQL statement.
 */
final class SegmentContext
{
    public const VALUE           = 'value';
    public const IDENTIFIER      = 'identifier';
    public const KEYWORD         = 'keyword';
    public const IN_LIST         = 'in_list';
    public const STRING_LITERAL  = 'string_literal';
    public const CLAUSE_FRAGMENT = 'clause_fragment';
    public const UNKNOWN         = 'unknown';

    public function __construct(
        public readonly string $kind,
        /** numeric | like | sort_direction | order_status | ... */
        public readonly ?string $hint = null,
        /** for STRING_LITERAL: literal text before/after the marker inside the quotes */
        public readonly ?string $literalPrefix = null,
        public readonly ?string $literalSuffix = null,
        public readonly string $detail = '',
    ) {}
}
