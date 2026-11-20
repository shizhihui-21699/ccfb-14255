<?php
declare(strict_types=1);

/**
 * Child process: execute one scenario function against the REWRITTEN app.
 * Outputs JSON {result, error}.
 */

require __DIR__ . '/../../vendor/autoload.php';

use SqlRewriter\Config;
use SqlRewriter\Runtime\SafeDb;
use SqlRewriter\Runtime\SqlGuard;

$base = dirname(__DIR__, 2);
$db = $base . '/output/harness_rewritten.sqlite';

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$config = Config::default();
SafeDb::configure($pdo, new SqlGuard($config->schema, $config->enums));

require $base . '/sample-app/rewritten/user_repository.php';
require $base . '/sample-app/rewritten/order_repository.php';
require $base . '/sample-app/rewritten/audit_repository.php';

$fn = $argv[1] ?? '';
$args = unserialize(base64_decode($argv[2] ?? '', true) ?: '');

try {
    $result = $fn(...$args);
    echo json_encode(['result' => $result, 'error' => null], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    echo json_encode(['result' => null, 'error' => get_class($e) . ': ' . $e->getMessage()]);
}
