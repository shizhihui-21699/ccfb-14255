<?php
declare(strict_types=1);
namespace SqlRewriter\Analyzer;

final class LiteralStep extends BuildStep
{
    public function __construct(public readonly string $text) {}

    public function equals(BuildStep $other): bool
    {
        return $other instanceof self && $other->text === $this->text;
    }
}
