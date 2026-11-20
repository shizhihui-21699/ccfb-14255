<?php
declare(strict_types=1);

/**
 * Legacy database layer — typical of a 10+ year old PHP system.
 *
 * Every helper executes a *fully concatenated* SQL string. The rewriter
 * is pointed at these function names as the SQL execution sinks.
 */

function legacy_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . getenv('RW_SQLITE_DB'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

/** Execute arbitrary concatenated SQL; return all rows as assoc arrays. */
function legacy_db_query(string $sql): array
{
    $stmt = legacy_db()->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function legacy_db_get_all(string $sql): array
{
    return legacy_db_query($sql);
}

/** First row as assoc array, or null. */
function legacy_db_get_row(string $sql): ?array
{
    $stmt = legacy_db()->query($sql);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** Flat list of the first column. */
function legacy_db_get_column(string $sql): array
{
    $stmt = legacy_db()->query($sql);
    $values = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    return $values === false ? [] : $values;
}

/** Write statement; returns affected row count. */
function legacy_db_execute(string $sql, string $mode = 'write'): int
{
    $stmt = legacy_db()->prepare($sql);
    $stmt->execute();
    return $stmt->rowCount();
}

/** Debug logging sink that historically received raw SQL. */
function legacy_log_raw_sql(string $sql): void
{
    // In the real system this wrote to a debug log. Kept as a sink so the
    // rewriter has to handle non-execution SQL consumers too.
}

/**
 * The legacy "escape" helper. It reduces risk but is not parameterization:
 * the value is still concatenated, and quoting context is the caller's job.
 */
function legacy_escape(string $value): string
{
    return str_replace(["\\", "'", "\0"], ["\\\\", "''", ''], $value);
}
