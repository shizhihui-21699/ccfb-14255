<?php
// Minimal smoke fixture covering the main fragment kinds.

function find_user($id) {
    $sql = "SELECT id, username, email FROM users WHERE id = " . $id;
    return legacy_db_get_row($sql);
}

function search_users($name, $order, $dir) {
    $sql = "SELECT * FROM users WHERE username LIKE '%" . $name . "%' ORDER BY " . $order . " " . $dir;
    return legacy_db_get_all($sql);
}

function list_by_ids($ids) {
    $sql = "SELECT * FROM users WHERE id IN (" . implode(',', $ids) . ")";
    return legacy_db_get_all($sql);
}

function dynamic_where($cond) {
    $sql = "SELECT * FROM users WHERE " . $cond;
    return legacy_db_get_all($sql);
}
