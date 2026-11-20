<?php
declare(strict_types=1);

/**
 * Audit repository — logging statements and a couple of patterns that
 * intentionally concatenate legitimate SQL fragments the rewriter must
 * not silently "fix" into a different query.
 */

/** A1: insert with fully dynamic values (all bindable). */
function a1_log_action(int $userId, string $action, string $detail): int
{
    $sql = "INSERT INTO audit_log (user_id, action, detail, created_at) VALUES ("
         . $userId . ", '" . $action . "', '" . $detail . "', datetime('now'))";
    return legacy_db_execute($sql);
}

/** A2: select with two bound values joined by AND. */
function a2_find_user_actions(int $userId, string $action): array
{
    $sql = "SELECT id, action, detail, created_at FROM audit_log "
         . "WHERE user_id = " . $userId . " AND action = '" . $action . "' ORDER BY id DESC";
    return legacy_db_get_all($sql);
}

/**
 * A3: deliberately legal fragment concatenation — the "intentional
 * fragment" case. The fragment is built only from internal literals
 * (no user input), so behavior must remain byte-identical after rewrite.
 */
function a3_internal_fragment(string $detail): array
{
    $internalOnly = "action = 'login'"; // no tainted data in this fragment
    $sql = "SELECT id, user_id FROM audit_log WHERE " . $internalOnly
         . " AND detail = '" . $detail . "'";
    return legacy_db_get_all($sql);
}

/**
 * A4: GROUP BY on a dynamic but whitelisted column + HAVING with a bound value.
 */
function a4_group_by(string $groupColumn, int $minCount): array
{
    $allowed = ['user_id', 'action'];
    if (!in_array($groupColumn, $allowed, true)) {
        $groupColumn = 'action';
    }
    $sql = "SELECT " . $groupColumn . " AS g, COUNT(*) AS n FROM audit_log "
         . "GROUP BY " . $groupColumn . " HAVING COUNT(*) >= " . $minCount;
    return legacy_db_get_all($sql);
}

/** A5: a function whose return shape changes by branch — must be refused. */
function a5_shape_changing_branch(bool $countOnly, int $userId): array
{
    if ($countOnly) {
        $sql = "SELECT COUNT(*) AS n FROM audit_log WHERE user_id = " . $userId;
    } else {
        $sql = "SELECT id, action, detail FROM audit_log WHERE user_id = " . $userId;
    }
    return legacy_db_get_all($sql);
}
