<?php
declare(strict_types=1);

namespace SqlRewriter;

/**
 * One finding per SQL execution sink.
 */
final class Issue
{
    public const REWRITTEN                 = 'rewritten';
    public const STATIC_NO_CHANGE          = 'static-no-change';
    public const SKIP_DYNAMIC_SINK         = 'skip-dynamic-sink-name';
    public const SKID_RECONSTRUCT          = 'skip-reconstruct-failed';
    public const SKIP_OPAQUE_STRUCTURE     = 'skip-opaque-sql-fragment';
    public const SKIP_IDENTIFIER_WHITELIST = 'skip-identifier-no-whitelist';
    public const SKIP_STRUCTURE_UNKNOWN    = 'skip-unclassifiable-position';
    public const SKIP_MULTIPLE_STATEMENTS  = 'skip-stacked-statements';
    public const SKIP_SPRINTF_FORMAT       = 'skip-unsupported-sprintf';
    public const SKIP_RECURSIVE_SUMMARY    = 'skip-recursive-helper';
    public const SKIP_UNSUPPORTED_EXPR     = 'skip-unsupported-expression';
    public const SKIP_DYNAMIC_CONNECTION   = 'skip-dynamic-connection';

    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $sink,
        public readonly string $status,
        public readonly string $reasonCode,
        public readonly string $explanation,
        public readonly ?string $originalSqlShape = null,
        public readonly ?string $rewrittenSqlShape = null,
        /** @var list<array{position:string, context:string, decision:string, detail:string}> */
        public readonly array $segments = [],
    ) {}

    public function isRewritten(): bool
    {
        return $this->status === self::REWRITTEN;
    }
}
