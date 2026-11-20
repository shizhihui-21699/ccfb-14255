<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

/**
 * Stable reason codes used both in ReconstructException and Issue records.
 */
final class IssueReason
{
    public const OPAQUE_FRAGMENT       = 'skip-opaque-sql-fragment';
    public const IDENTIFIER_NO_WHITELIST = 'skip-identifier-no-whitelist';
    public const STRUCTURE_UNKNOWN     = 'skip-unclassifiable-position';
    public const STACKED_STATEMENTS    = 'skip-stacked-statements';
    public const RECONSTRUCT_FAILED    = 'skip-reconstruct-failed';
    public const UNSUPPORTED_EXPR      = 'skip-unsupported-expression';
    public const SPRINTF_FORMAT        = 'skip-unsupported-sprintf';
    public const DYNAMIC_SINK          = 'skip-dynamic-sink-name';
    public const DYNAMIC_CONNECTION    = 'skip-dynamic-connection';
}
