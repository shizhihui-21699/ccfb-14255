<?php
declare(strict_types=1);

/**
 * Database schema + deterministic seed data shared by the original and
 * rewritten versions of the sample application.
 */

function sample_schema_sql(): string
{
    return <<<SQL
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS audit_log;

CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE,
    email         TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'viewer',
    status        TEXT NOT NULL DEFAULT 'active',
    created_at    TEXT NOT NULL
);

CREATE TABLE orders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    product    TEXT NOT NULL,
    amount     REAL NOT NULL,
    status     TEXT NOT NULL DEFAULT 'pending',
    created_at TEXT NOT NULL
);

CREATE TABLE audit_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    action     TEXT NOT NULL,
    detail     TEXT NOT NULL,
    created_at TEXT NOT NULL
);
SQL;
}

function sample_seed_sql(): array
{
    $users = [];
    $roles = ['admin', 'editor', 'viewer'];
    for ($i = 1; $i <= 12; $i++) {
        $role = $roles[$i % 3];
        $status = $i % 4 === 0 ? 'disabled' : 'active';
        $users[] = sprintf(
            "INSERT INTO users (id, username, email, password_hash, role, status, created_at) VALUES (%d, 'user%02d', 'user%02d@example.test', 'hash%02d', '%s', '%s', '2026-01-%02d 10:00:00')",
            $i, $i, $i, $i, $role, $status, ($i % 28) + 1,
        );
    }

    $products = ['Keyboard', 'Monitor', 'Laptop', 'Mouse', 'Webcam'];
    $statuses = ['pending', 'paid', 'shipped', 'cancelled', 'refunded'];
    $orders = [];
    for ($i = 1; $i <= 20; $i++) {
        $orders[] = sprintf(
            "INSERT INTO orders (id, user_id, product, amount, status, created_at) VALUES (%d, %d, '%s', %.2f, '%s', '2026-02-%02d 09:30:00')",
            $i,
            (($i - 1) % 12) + 1,
            $products[$i % 5],
            $i * 12.5,
            $statuses[$i % 5],
            ($i % 28) + 1,
        );
    }

    $actions = ['login', 'logout', 'purchase', 'refund', 'profile_update'];
    $audit = [];
    for ($i = 1; $i <= 24; $i++) {
        $audit[] = sprintf(
            "INSERT INTO audit_log (id, user_id, action, detail, created_at) VALUES (%d, %d, '%s', 'detail %02d', '2026-03-%02d 08:15:00')",
            $i,
            (($i - 1) % 12) + 1,
            $actions[$i % 5],
            $i,
            ($i % 28) + 1,
        );
    }

    return array_merge($users, $orders, $audit);
}

function sample_init_database(string $path): PDO
{
    if (file_exists($path)) {
        unlink($path);
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(sample_schema_sql());
    foreach (sample_seed_sql() as $statement) {
        $pdo->exec($statement);
    }
    return $pdo;
}
