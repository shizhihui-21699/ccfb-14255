<?php

declare (strict_types=1);
/**
 * Order repository — dynamic identifiers that *are* schema-whitelisted
 * and therefore rewriteable with runtime guards, plus a few more
 * deliberately dangerous patterns.
 */
/** O1: dynamic table name that only ever names a known table. */
function o1_count_in_table(string $table): int
{
    // The application invariant says $table is one of a fixed set; the
    // rewriter proves every literal path is whitelisted and emits a
    // runtime identifier guard for the non-literal value.
    $sql = "SELECT COUNT(*) AS n FROM " . $table;
    $row = \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_row('SELECT COUNT(*) AS n FROM __RWI0__', [\SqlRewriter\Runtime\Binding::identifier($table, 'table')]);
    return (int) ($row['n'] ?? 0);
}
/** O2: insert with bound values in the VALUES tuple. */
function o2_insert_order(int $userId, string $product, float $amount, string $status): int
{
    $sql = "INSERT INTO orders (user_id, product, amount, status, created_at) VALUES (" . $userId . ", '" . $product . "', " . $amount . ", '" . $status . "', datetime('now'))";
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_execute('INSERT INTO orders (user_id, product, amount, status, created_at) VALUES (__RWV0__, __RWV1__, __RWV2__, __RWV3__, datetime(\'now\'))', [\SqlRewriter\Runtime\Binding::value($userId), \SqlRewriter\Runtime\Binding::value($product, '', ''), \SqlRewriter\Runtime\Binding::value($amount), \SqlRewriter\Runtime\Binding::value($status, '', '')], 'write');
}
/** O3: update with a bound SET value and a bound WHERE value. */
function o3_update_status(int $orderId, string $status): int
{
    $sql = "UPDATE orders SET status = '" . $status . "' WHERE id = " . $orderId;
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_execute('UPDATE orders SET status = __RWV0__ WHERE id = __RWV1__', [\SqlRewriter\Runtime\Binding::value($status, '', ''), \SqlRewriter\Runtime\Binding::value($orderId)]);
}
/** O4: qualified column reference (table.column) assembled dynamically. */
function o4_qualified_column(string $table, string $column, int $userId): array
{
    $sql = "SELECT " . $table . "." . $column . " AS v FROM " . $table . " WHERE " . $table . ".user_id = " . $userId;
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_all('SELECT __RWI0__.__RWI1__ AS v FROM __RWI2__ WHERE __RWI3__.user_id = __RWV4__', [\SqlRewriter\Runtime\Binding::identifier($table, 'table'), \SqlRewriter\Runtime\Binding::identifier($column, 'column'), \SqlRewriter\Runtime\Binding::identifier($table, 'table'), \SqlRewriter\Runtime\Binding::identifier($table, 'table'), \SqlRewriter\Runtime\Binding::value($userId)]);
}
/** O5: nested helper that appends a condition (two-level inlining). */
function o5_two_level_helper(int $userId, string $status): array
{
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_all('SELECT id, user_id, product, amount, status FROM orders WHERE user_id = __RWV0__ AND status = __RWV1__', [\SqlRewriter\Runtime\Binding::value($userId), \SqlRewriter\Runtime\Binding::value($status, '', '')]);
}
/** O6: coalesce choosing between two bindable values. */
function o6_coalesce(?string $status): array
{
    $fallback = 'pending';
    $sql = "SELECT id, product, amount FROM orders WHERE status = '" . ($status ?? $fallback) . "'";
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_all('SELECT id, product, amount FROM orders WHERE status = __RWV0__', [\SqlRewriter\Runtime\Binding::value($status ?? 'pending', '', '')]);
}
/** O7: LIKE with a manually pre-built pattern (wildcards outside the value). */
function o7_like_prefix(string $prefix): array
{
    $pattern = $prefix . '%';
    $sql = "SELECT id, product FROM orders WHERE product LIKE '" . $pattern . "'";
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_all('SELECT id, product FROM orders WHERE product LIKE __RWV0__', [\SqlRewriter\Runtime\Binding::value($prefix, '', '%')]);
}
/** O8: dynamic keyword that is NOT a known enum — must be refused. */
function o8_unknown_keyword(int $userId, string $operator): array
{
    $sql = "SELECT id, amount FROM orders WHERE amount " . $operator . " 100 AND user_id = " . $userId;
    return legacy_db_get_all($sql);
}
/** O9: string passed through intval — numeric position, provably safe. */
function o9_intval_limit(string $limit): array
{
    $sql = "SELECT id, product FROM orders ORDER BY id LIMIT " . intval($limit);
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_all('SELECT id, product FROM orders ORDER BY id LIMIT __RWV0__', [\SqlRewriter\Runtime\Binding::value(intval($limit))]);
}
function build_orders_where(int $userId, string $status): string
{
    $sql = orders_base_query() . " WHERE user_id = " . $userId . " AND status = '" . $status . "'";
    return $sql;
}
function orders_base_query(): string
{
    return "SELECT id, user_id, product, amount, status FROM orders";
}