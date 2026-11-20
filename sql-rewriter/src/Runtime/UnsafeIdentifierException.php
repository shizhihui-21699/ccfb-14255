<?php
declare(strict_types=1);

namespace SqlRewriter\Runtime;

/**
 * Thrown when a dynamic identifier/keyword fails its whitelist check at
 * runtime. The exception is deliberate: the alternative of silently
 * dropping the clause would change query semantics.
 */
class UnsafeIdentifierException extends \RuntimeException
{
    public function __construct(
        public readonly string $value,
        public readonly string $kind,
    ) {
        parent::__construct("Unsafe SQL {$kind} blocked by whitelist: " . $this->display($value));
    }

    private function display(string $value): string
    {
        $visible = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '?';
        return "'" . mb_substr($visible, 0, 80) . "'";
    }
}
