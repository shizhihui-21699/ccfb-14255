<?php
declare(strict_types=1);
namespace SqlRewriter\Analyzer;

abstract class BuildStep
{
    abstract public function equals(BuildStep $other): bool;
}
