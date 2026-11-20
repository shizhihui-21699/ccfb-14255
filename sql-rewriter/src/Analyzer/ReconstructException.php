<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

final class ReconstructException extends \RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
