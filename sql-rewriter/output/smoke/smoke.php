<?php

// Minimal smoke fixture covering the main fragment kinds.
function find_user($id)
{
    $sql = "SELECT id, username, email FROM users WHERE id = " . $id;
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_row('SELECT id, username, email FROM users WHERE id = __RWV0__', [\SqlRewriter\Runtime\Binding::value($id)]);
}
function search_users($name, $order, $dir)
{
    $sql = "SELECT * FROM users WHERE username LIKE '%" . $name . "%' ORDER BY " . $order . " " . $dir;
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_all('SELECT * FROM users WHERE username LIKE \'%__RWV0__%\' ORDER BY __RWI1__ __RWE2__', [\SqlRewriter\Runtime\Binding::value($name, '%', '%'), \SqlRewriter\Runtime\Binding::identifier($order, 'column'), \SqlRewriter\Runtime\Binding::enum($dir, 'sort_direction')]);
}
function list_by_ids($ids)
{
    $sql = "SELECT * FROM users WHERE id IN (" . implode(',', $ids) . ")";
    return \SqlRewriter\Runtime\SafeDbCompat::legacy_db_get_all('SELECT * FROM users WHERE id IN (__RWL0__)', [\SqlRewriter\Runtime\Binding::inList($ids)]);
}
function dynamic_where($cond)
{
    $sql = "SELECT * FROM users WHERE " . $cond;
    return legacy_db_get_all($sql);
}