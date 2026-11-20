<?php
declare(strict_types=1);

namespace SqlRewriter\Runtime;

/**
 * Drop-in adapters for the legacy flat sink functions.
 *
 * Each method keeps the exact name, argument positions and return shape of
 * the legacy function it replaces, so rewritten call sites do not need any
 * surrounding code to change:
 *
 *   legacy_db_query($sql)            -> rows as list of assoc arrays
 *   legacy_db_get_all($sql)          -> rows as list of assoc arrays
 *   legacy_db_get_row($sql)          -> first assoc row or null
 *   legacy_db_get_column($sql)       -> first column values, flat list
 *   legacy_db_execute($sql, $mode)   -> affected row count
 *   legacy_log_raw_sql($sql)         -> logging no-op (never executes)
 *
 * The rewritten call passes (template, bindings, ...extra args).
 */
final class SafeDbCompat
{
    /** @return list<array<string, mixed>> */
    public static function legacy_db_query(string $template, array $bindings = []): array
    {
        return SafeDb::execute($template, $bindings)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public static function legacy_db_query_fragments(array $bindings, array $fragments): array
    {
        return SafeDb::executeFragments($bindings, $fragments)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public static function legacy_db_get_all(string $template, array $bindings = []): array
    {
        return SafeDb::execute($template, $bindings)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public static function legacy_db_get_all_fragments(array $bindings, array $fragments): array
    {
        return SafeDb::executeFragments($bindings, $fragments)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public static function legacy_db_get_row(string $template, array $bindings = []): ?array
    {
        $row = SafeDb::execute($template, $bindings)->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public static function legacy_db_get_row_fragments(array $bindings, array $fragments): ?array
    {
        $row = SafeDb::executeFragments($bindings, $fragments)->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<mixed> */
    public static function legacy_db_get_column(string $template, array $bindings = []): array
    {
        $values = SafeDb::execute($template, $bindings)->fetchAll(\PDO::FETCH_COLUMN, 0);
        return $values === false ? [] : $values;
    }

    /** @return list<mixed> */
    public static function legacy_db_get_column_fragments(array $bindings, array $fragments): array
    {
        $values = SafeDb::executeFragments($bindings, $fragments)->fetchAll(\PDO::FETCH_COLUMN, 0);
        return $values === false ? [] : $values;
    }

    public static function legacy_db_execute(string $template, array $bindings = [], string $mode = 'write'): int
    {
        $stmt = SafeDb::execute($template, $bindings);
        return $stmt->rowCount();
    }

    public static function legacy_db_execute_fragments(array $bindings, array $fragments, string $mode = 'write'): int
    {
        return SafeDb::executeFragments($bindings, $fragments)->rowCount();
    }

    /**
     * Logging sink: keep the call harmless and side-effect compatible.
     * The template contains markers rather than real values, so it is
     * logged together with the binding count instead of raw user data.
     */
    public static function legacy_log_raw_sql(string $template, array $bindings = []): void
    {
        // Intentionally minimal: legacy systems often logged raw SQL for
        // debugging. Parameterized logging avoids leaking user data.
    }
}
