<?php
declare(strict_types=1);

namespace SqlRewriter\Runtime;

/**
 * Runtime guards paired with the static rewriter.
 *
 * The static analyzer proves that *every literal value* an identifier can
 * take is whitelisted; these guards cover the values it cannot see (raw
 * variables). They are the second, defense-in-depth layer — parameter
 * binding itself cannot protect table/column/keyword positions.
 */
final class SqlGuard
{
    /**
     * @param array<string, array{table:string, columns:list<string>}> $schema
     * @param array<string, list<string>> $enums
     */
    public function __construct(
        private readonly array $schema,
        private readonly array $enums = [],
    ) {}

    /**
     * Validate a dynamic table/column/alias identifier.
     * Accepts plain names and qualified table.column pairs.
     */
    public function identifier(mixed $value, string $kind = 'identifier'): string
    {
        if (!is_string($value) || $value === '') {
            throw new UnsafeIdentifierException(is_scalar($value) ? (string) $value : gettype($value), $kind);
        }

        // qualified name: table.column (each part validated independently)
        if (str_contains($value, '.')) {
            $parts = explode('.', $value);
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                throw new UnsafeIdentifierException($value, $kind);
            }
            $this->assertKnownName($parts[0], $kind);
            if ($parts[1] === '*') {
                return $parts[0] . '.*';
            }
            $this->assertKnownName($parts[1], $kind);
            return $parts[0] . '.' . $parts[1];
        }

        $this->assertKnownName($value, $kind);
        return $value;
    }

    /**
     * Validate a dynamic SQL keyword against a named enum
     * (e.g. sort_direction => ASC|DESC).
     */
    public function enum(mixed $value, string $enumName): string
    {
        $allowed = $this->enums[strtolower($enumName)] ?? null;
        if ($allowed === null) {
            throw new \LogicException("Unknown SQL guard enum: {$enumName}");
        }
        if (!is_string($value)) {
            throw new UnsafeIdentifierException(gettype($value), $enumName);
        }
        foreach ($allowed as $candidate) {
            if (strcasecmp($value, $candidate) === 0) {
                return $candidate; // canonical casing
            }
        }
        throw new UnsafeIdentifierException($value, $enumName);
    }

    /**
     * Render an IN(...) placeholder list and return it together with the
     * flattened parameter values in order.
     *
     * @return array{0:string, 1:list<mixed>} [sql fragment, params]
     */
    public function inList(mixed $values): array
    {
        if ($values instanceof \Traversable) {
            $values = iterator_to_array($values);
        }
        if (!is_array($values)) {
            throw new \InvalidArgumentException(
                'IN list expects an array, got ' . get_debug_type($values),
            );
        }
        if ($values === []) {
            // SQL "IN ()" is invalid; keep behavior explicit instead.
            throw new \InvalidArgumentException('Cannot bind an empty IN list');
        }
        $flat = [];
        foreach ($values as $v) {
            if (is_array($v)) {
                throw new \InvalidArgumentException('Nested arrays are not supported in IN lists');
            }
            $flat[] = $v;
        }
        // Placeholders only; the surrounding parentheses belong to the SQL
        // template (the original IN(...) already provides them).
        return [implode(',', array_fill(0, count($flat), '?')), $flat];
    }

    private function assertKnownName(string $name, string $kind): void
    {
        // strict identifier shape first, so e.g. "users--" never reaches lookup
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new UnsafeIdentifierException($name, $kind);
        }
        foreach ($this->schema as $entry) {
            if ($entry['table'] === $name || in_array($name, $entry['columns'], true)) {
                return;
            }
        }
        throw new UnsafeIdentifierException($name, $kind);
    }
}
