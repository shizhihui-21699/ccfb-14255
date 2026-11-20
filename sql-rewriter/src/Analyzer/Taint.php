<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

use PhpParser\Node\Expr;

/**
 * Taint fact attached to every variable / array-element expression.
 *
 * A fact remembers two independent flags:
 *  - tainted:  raw user-controlled data flows into the value
 *  - escaped:  the value has passed through a configured escaping helper
 * A value that is both (e.g. escaped input concatenated with raw input)
 * stays tainted: a single quote can still break out of the string.
 */
final class Taint
{
    private function __construct(
        public readonly bool $tainted,
        public readonly bool $escaped,
        public readonly ?string $origin = null,
    ) {}

    public static function safe(?string $origin = null): self
    {
        return new self(false, false, $origin);
    }

    public static function user(string $origin = 'input'): self
    {
        return new self(true, false, $origin);
    }

    public static function escaped(string $origin = 'escaped'): self
    {
        return new self(false, true, $origin);
    }

    public static function mixed(): self
    {
        return new self(true, true, 'mixed');
    }

    public function isTainted(): bool
    {
        return $this->tainted;
    }

    public function isEscaped(): bool
    {
        return $this->escaped;
    }

    /** Conservative lattice join: any tainted branch poisons the merge. */
    public static function join(?Taint $a, ?Taint $b): Taint
    {
        $a ??= self::safe('bottom');
        $b ??= self::safe('bottom');
        if ($a->tainted || $b->tainted) {
            return ($a->escaped || $b->escaped) ? self::mixed() : self::user('joined');
        }
        if ($a->escaped || $b->escaped) {
            return self::escaped('joined');
        }
        return self::safe('joined');
    }

    public function describe(): string
    {
        if ($this->tainted && $this->escaped) {
            return 'tainted+escaped(mixed)';
        }
        if ($this->tainted) {
            return 'tainted(' . ($this->origin ?? '?') . ')';
        }
        if ($this->escaped) {
            return 'escaped';
        }
        return 'safe(' . ($this->origin ?? 'literal') . ')';
    }
}
