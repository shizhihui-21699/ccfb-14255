<?php
declare(strict_types=1);

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
    return legacy_db_get_row($sql);
}

/** U2: string value concatenated *inside* quoted literal (LIKE). */
function u2_search_by_name(string $name): array
{
    $sql = "SELECT id, username, email FROM users WHERE username LIKE '%" . $name . "%'";
    return legacy_db_get_all($sql);
}

/** U3: escaped value — legacy_escape() must be turned into real binding. */
function u3_login_lookup(string $username): ?array
{
    $safe = legacy_escape($username);
    $sql = "SELECT id, username, password_hash, role FROM users WHERE username = '" . $safe . "'";
    return legacy_db_get_row($sql);
}

/** U4: dynamic ORDER BY column + direction, guarded by an application whitelist. */
function u4_list_sorted(string $orderColumn, string $direction): array
{
    $allowedColumns = ['id', 'username', 'email', 'created_at'];
    if (!in_array($orderColumn, $allowedColumns, true)) {
        $orderColumn = 'id';
    }
    $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
    $sql = "SELECT id, username, email, role, status, created_at FROM users "
         . "ORDER BY " . $orderColumn . " " . $direction;
    return legacy_db_get_all($sql);
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
    return legacy_db_get_all($sql);
}

/** U6: IN list built with implode over an array. */
function u6_find_in_ids(array $ids): array
{
    $sql = "SELECT id, username, email FROM users WHERE id IN (" . implode(',', $ids) . ")";
    return legacy_db_get_all($sql);
}

/** U7: SQL assembled inside a helper function and inlined at the sink. */
function u7_via_helper(string $email): ?array
{
    $sql = build_email_lookup($email);
    return legacy_db_get_row($sql);
}

/** U8: sprintf with numeric and string conversions. */
function u8_sprintf_lookup(string $role, int $minId): array
{
    $sql = sprintf("SELECT id, username, email FROM users WHERE role = '%s' AND id >= %d", $role, $minId);
    return legacy_db_get_all($sql);
}

/** U9: ternary selecting a bound value while the SQL shape stays identical. */
function u9_ternary_value(?string $role): array
{
    $effective = $role === null ? 'viewer' : $role;
    $sql = "SELECT id, username FROM users WHERE role = '" . $effective . "'";
    return legacy_db_get_all($sql);
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
    return legacy_db_get_row($sql);
}

/** U12: dynamic projection column, never whitelisted. */
function u12_dynamic_column(string $column, int $id): array
{
    $sql = "SELECT id, " . $column . " AS picked FROM users WHERE id = " . $id;
    return legacy_db_get_all($sql);
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
