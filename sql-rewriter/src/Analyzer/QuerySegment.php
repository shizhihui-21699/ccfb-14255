<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

use PhpParser\Node\Expr;

/**
 * One piece of a reconstructed SQL statement.
 * Literal pieces hold static SQL text; dynamic pieces hold the PHP
 * expression that produced the fragment plus its taint fact.
 */
final class QuerySegment
{
    public const LITERAL  = 'literal';
    public const DYNAMIC  = 'dynamic';
    public const IN_LIST  = 'in_list';
    public const OPTIONAL = 'optional';

    private function __construct(
        public readonly string $kind,
        public readonly ?string $text,
        public readonly ?Expr $expr,
        public readonly ?Taint $taint,
        /** IN_LIST only: glue used by implode/join */
        public readonly ?string $glue = null,
        /** source location for diagnostics */
        public readonly ?int $line = null,
        /** OPTIONAL only: runtime condition guarding the inner segments */
        public readonly ?Expr $condition = null,
        /** OPTIONAL only: inner segments included when condition is true */
        public readonly array $inner = [],
    ) {}

    public static function literal(string $text, ?int $line = null): self
    {
        return new self(self::LITERAL, $text, null, null, null, $line);
    }

    public static function dynamic(Expr $expr, Taint $taint, ?int $line = null): self
    {
        return new self(self::DYNAMIC, null, $expr, $taint, null, $line ?? $expr->getLine());
    }

    public static function inList(Expr $arrayExpr, Taint $taint, string $glue, ?int $line = null): self
    {
        return new self(self::IN_LIST, null, $arrayExpr, $taint, $glue, $line ?? $arrayExpr->getLine());
    }

    /**
     * @param list<QuerySegment> $inner
     */
    public static function optional(?Expr $condition, array $inner): self
    {
        return new self(self::OPTIONAL, null, null, null, null, null, $condition, $inner);
    }

    /**
     * Return a copy with a different guard condition (used when optional
     * groups are flattened in the planner).
     */
    public function withCondition(?Expr $condition): self
    {
        return new self(
            $this->kind,
            $this->text,
            $this->expr,
            $this->taint,
            $this->glue,
            $this->line,
            $condition,
            $this->inner,
        );
    }
}
