<?php

declare (strict_types=1);
/**
 * User repository — a cross-section of real legacy concatenation patterns.
 *
 * Each function is numbered so the test harness can map results back to a
 * named scenario. Functions U1..U9 are intended to be safely rewriteable;
 * U10..U14 contain patterns the tool must refuse with a precise reason.
 */
/* ---------------------------------------------------------------- safe cases */
/** U1: single concatenated numeric value. */
function u1_find_by_id(int|string $id): ?array
{
    $sql = "SELECT id, username, email, role, status FROM users WHERE id = " . $id;
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_row('SELECT id, username, email, role, status FROM users WHERE id = __RWV0__', [\SqlRewriter\Runtime\Binding::value($id)]);
}
/** U2: string value concatenated *inside* quoted literal (LIKE). */
function u2_search_by_name(string $name): array
{
    $sql = "SELECT id, username, email FROM users WHERE username LIKE '%" . $name . "%'";
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_all('SELECT id, username, email FROM users WHERE username LIKE __RWV0__', [\SqlRewriter\Runtime\Binding::value($name, '%', '%')]);
}
/** U3: escaped value — legacy_escape() must be turned into real binding. */
function u3_login_lookup(string $username): ?array
{
    $safe = legacy_escape($username);
    $sql = "SELECT id, username, password_hash, role FROM users WHERE username = '" . $safe . "'";
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_row('SELECT id, username, password_hash, role FROM users WHERE username = __RWV0__', [\SqlRewriter\Runtime\Binding::value($username, '', '')]);
}
/** U4: dynamic ORDER BY column + direction, guarded by an application whitelist. */
function u4_list_sorted(string $orderColumn, string $direction): array
{
    $allowedColumns = ['id', 'username', 'email', 'created_at'];
    if (!in_array($orderColumn, $allowedColumns, true)) {
        $orderColumn = 'id';
    }
    $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
    $sql = "SELECT id, username, email, role, status, created_at FROM users " . "ORDER BY " . $orderColumn . " " . $direction;
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_all('SELECT id, username, email, role, status, created_at FROM users ORDER BY id __RWE0__', [\SqlRewriter\Runtime\Binding::enum(strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC', 'sort_direction')]);
}
/** U5: variable number of conditions built in branches (append-and-keep). */
function u5_dynamic_filters(array $filters): array
{
    $sql = "SELECT id, username, email, role FROM users WHERE 1 = 1";
    if (!empty($filters['role'])) {
        $sql .= " AND role = '" . $filters['role'] . "'";
    }
    if (!empty($filters['status'])) {
        $sql .= " AND status = '" . $filters['status'] . "'";
    }
    if (isset($filters['limit'])) {
        $sql .= " LIMIT " . (int) $filters['limit'];
    }
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_all_fragments([\SqlRewriter\Runtime\Binding::value($filters['role'], '', ''), \SqlRewriter\Runtime\Binding::value($filters['status'], '', ''), \SqlRewriter\Runtime\Binding::value((int) $filters['limit'])], [new \SqlRewriter\Runtime\Fragment('SELECT id, username, email, role FROM users WHERE 1 = 1', null, []), new \SqlRewriter\Runtime\Fragment(' AND role = __RWV0__', !empty($filters['role']), [0]), new \SqlRewriter\Runtime\Fragment(' AND status = __RWV1__', !empty($filters['status']), [1]), new \SqlRewriter\Runtime\Fragment(' LIMIT __RWV2__', isset($filters['limit']), [2])]);
}
/** U6: IN list built with implode over an array. */
function u6_find_in_ids(array $ids): array
{
    $sql = "SELECT id, username, email FROM users WHERE id IN (" . implode(',', $ids) . ")";
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_all('SELECT id, username, email FROM users WHERE id IN (__RWL0__)', [\SqlRewriter\Runtime\Binding::inList($ids)]);
}
/** U7: SQL assembled inside a helper function and inlined at the sink. */
function u7_via_helper(string $email): ?array
{
    $sql = build_email_lookup($email);
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_row('SELECT id, username, email, role FROM users WHERE email = __RWV0__', [\SqlRewriter\Runtime\Binding::value($email, '', '')]);
}
/** U8: sprintf with numeric and string conversions. */
function u8_sprintf_lookup(string $role, int $minId): array
{
    $sql = sprintf("SELECT id, username, email FROM users WHERE role = '%s' AND id >= %d", $role, $minId);
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_all('SELECT id, username, email FROM users WHERE role = __RWV0__ AND id >= __RWV1__', [\SqlRewriter\Runtime\Binding::value($role, '', ''), \SqlRewriter\Runtime\Binding::value($minId)]);
}
/** U9: ternary selecting a bound value while the SQL shape stays identical. */
function u9_ternary_value(?string $role): array
{
    $effective = $role === null ? 'viewer' : $role;
    $sql = "SELECT id, username FROM users WHERE role = '" . $effective . "'";
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_all('SELECT id, username FROM users WHERE role = __RWV0__', [\SqlRewriter\Runtime\Binding::value($role === null ? 'viewer' : $role, '', '')]);
}
/* -------------------------------------------------------------- unsafe cases */
/** U10: a whole boolean condition fragment is concatenated (not a value). */
function u10_raw_condition(string $condition): array
{
    $sql = "SELECT id, username FROM users WHERE " . $condition;
    return legacy_db_get_all($sql);
}
/** U11: dynamic table name with no whitelist anywhere. */
function u11_dynamic_table(string $table, int $id): ?array
{
    $sql = "SELECT * FROM " . $table . " WHERE id = " . $id;
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_row('SELECT * FROM __RWI0__ WHERE id = __RWV1__', [\SqlRewriter\Runtime\Binding::identifier($table, 'table'), \SqlRewriter\Runtime\Binding::value($id)]);
}
/** U12: dynamic projection column, never whitelisted. */
function u12_dynamic_column(string $column, int $id): array
{
    $sql = "SELECT id, " . $column . " AS picked FROM users WHERE id = " . $id;
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_all('SELECT id, __RWI0__ AS picked FROM users WHERE id = __RWV1__', [\SqlRewriter\Runtime\Binding::identifier($column, 'column'), \SqlRewriter\Runtime\Binding::value($id)]);
}
/** U13: stacked query attempt after a bindable value. */
function u13_stacked(string $name): array
{
    $sql = "SELECT id, username FROM users WHERE username = '" . $name . "'; DROP TABLE audit_log";
    return legacy_db_get_all($sql);
}
/** U14: sprintf whose format string is itself dynamic. */
function u14_dynamic_format(string $format, string $value): array
{
    $sql = sprintf($format, $value);
    return legacy_db_get_all($sql);
}
/* ------------------------------------------------------------- helper used U7 */
/**
 * Builds an email lookup statement from its parameter.
 * Deliberately lives in a separate function to exercise cross-function
 * summary + inlining.
 */
function build_email_lookup(string $email): string
{
    $base = "SELECT id, username, email, role FROM users WHERE email = '";
    return $base . $email . "'";
}