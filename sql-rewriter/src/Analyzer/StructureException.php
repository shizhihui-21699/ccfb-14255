<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

final class StructureException extends \RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
