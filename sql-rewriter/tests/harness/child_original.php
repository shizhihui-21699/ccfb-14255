<?php
declare(strict_types=1);

/**
 * Child process: execute one scenario function against the ORIGINAL app.
 * Deliberately does NOT load vendor/autoload.php: the original app defines
 * its own legacy_* helpers and must run exactly as the legacy code did,
 * without the rewriter's compatibility stubs shadowing them.
 * Outputs JSON {result, error}.
 */

$base = dirname(__DIR__, 2);
$db = $base . '/output/harness_original.sqlite';
putenv('RW_SQLITE_DB=' . $db);

require $base . '/sample-app/original/legacy_db.php';
require $base . '/sample-app/original/user_repository.php';
require $base . '/sample-app/original/order_repository.php';
require $base . '/sample-app/original/audit_repository.php';

$fn = $argv[1] ?? '';
$args = unserialize(base64_decode($argv[2] ?? '', true) ?: '');

header('Content-Type: application/json');
try {
    $result = $fn(...$args);
    echo json_encode(['result' => $result, 'error' => null], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    echo json_encode(['result' => null, 'error' => get_class($e) . ': ' . $e->getMessage()]);
}
