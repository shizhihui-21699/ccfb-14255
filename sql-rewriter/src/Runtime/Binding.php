<?php
declare(strict_types=1);

namespace SqlRewriter\Runtime;

/**
 * One positional binding carried alongside a rewritten SQL template.
 *
 * The marker order in the template (__GUARD0__, ?, __INLIST1__, ...)
 * matches the binding array order. Plain "?" placeholders are filled from
 * VALUE bindings in sequence.
 */
final class Binding
{
    public const VALUE      = 'value';
    public const IDENTIFIER = 'identifier';
    public const ENUM       = 'enum';
    public const IN_LIST    = 'in_list';

    private function __construct(
        public readonly string $kind,
        public readonly mixed $value,
        public readonly ?string $hint = null,
        public readonly ?string $literalPrefix = null,
        public readonly ?string $literalSuffix = null,
        /** PDO parameter type for VALUE bindings (auto when null) */
        public readonly ?int $paramType = null,
    ) {}

    public static function value(mixed $value, ?string $prefix = null, ?string $suffix = null, ?int $paramType = null): self
    {
        return new self(self::VALUE, $value, null, $prefix, $suffix, $paramType);
    }

    public static function identifier(mixed $value, ?string $hint = null): self
    {
        return new self(self::IDENTIFIER, $value, $hint);
    }

    public static function enum(mixed $value, string $enumName): self
    {
        return new self(self::ENUM, $value, $enumName);
    }

    public static function inList(mixed $value): self
    {
        return new self(self::IN_LIST, $value);
    }
}
