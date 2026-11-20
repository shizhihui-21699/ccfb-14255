<?php
declare(strict_types=1);

namespace SqlRewriter\Runtime;

/**
 * One conditional SQL fragment paired with the binding indices it uses.
 *
 * The fragment's SQL contains __RW*__ markers whose numbers are GLOBAL
 * indices into the full bindings array. When $condition is null the
 * fragment is always included; otherwise it is included only when the
 * caller evaluates the condition as truthy.
 */
final class Fragment
{
    /**
     * @param list<int> $bindingIndices
     */
    public function __construct(
        public readonly string $sql,
        public readonly ?bool $active,
        public readonly array $bindingIndices,
    ) {}
}
