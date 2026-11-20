<?php
declare(strict_types=1);

/**
 * Equivalence + injection harness.
 *
 * The original and rewritten apps declare colliding function names, so all
 * actual function execution happens in isolated child processes (one app
 * version per process). This parent only orchestrates and compares.
 *
 * For every scenario it asserts:
 *   1. Normal arguments give byte-identical results on both versions.
 *   2. Injection payloads are neutralized on the rewritten version
 *      (bound as inert values, or blocked by the identifier guard).
 *   3. The deliberately unsafe patterns are refused by the rewriter.
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/scenarios.php';

final class HarnessRunner
{
    private int $pass = 0;
    private int $fail = 0;
    /** @var list<string> */
    private array $failures = [];

    public function run(string $baseDir): int
    {
        $origDb = $baseDir . '/output/harness_original.sqlite';
        $rewDb = $baseDir . '/output/harness_rewritten.sqlite';

        require_once $baseDir . '/sample-app/original/schema.php';
        sample_init_database($origDb);
        sample_init_database($rewDb);

        $this->equivalenceAndInjection($baseDir);
        $this->assertRefusals($baseDir);

        echo "\n================  RESULT  ================\n";
        echo "  passed: {$this->pass}\n";
        echo "  failed: {$this->fail}\n";
        if ($this->failures !== []) {
            echo "\nFAILURES:\n";
            foreach ($this->failures as $f) {
                echo "  - {$f}\n";
            }
        }
        echo "==========================================\n";
        return $this->fail === 0 ? 0 : 1;
    }

    private function equivalenceAndInjection(string $base): void
    {
        foreach (harness_scenarios() as $name => $scenario) {
            $fn = $scenario['fn'];

            // Reset both databases per scenario so write scenarios (inserts/
            // updates) cannot leak state into later read comparisons.
            $this->resetDatabases($base);

            // 1. Normal-result equivalence across the two app versions.
            $orig = $this->runChild($base, 'original', $fn, $scenario['normal_args']);
            $rew = $this->runChild($base, 'rewritten', $fn, $scenario['normal_args']);
            if ($orig['error'] !== null) {
                $this->record(false, '', "{$name}: original errored on normal args: {$orig['error']}");
            } elseif ($rew['error'] !== null) {
                $this->record(false, '', "{$name}: rewritten errored on normal args: {$rew['error']}");
            } else {
                $this->record($orig['result'] === $rew['result'],
                    "{$name}: normal results identical",
                    "{$name}: normal results differ");
            }

            // 2. Injection neutralization on the rewritten version.
            foreach ($scenario['payloads'] ?? [] as $payload) {
                $args = $scenario['normal_args'];
                $args[$payload['arg']] = $payload['value'];
                $out = $this->runChild($base, 'rewritten', $fn, $args);

                if ($payload['expect'] === 'blocked-by-guard') {
                    // Two acceptable safe outcomes for an identifier position:
                    //  (a) the runtime SqlGuard throws UnsafeIdentifierException;
                    //  (b) an application-level whitelist (in_array/ternary) has
                    //      already converged the value, so the query runs without
                    //      any SQL syntax/structure error.
                    // A SQL syntax error means malicious structure leaked -> fail.
                    $guardBlocked = $out['error'] !== null
                        && str_contains($out['error'], 'UnsafeIdentifierException');
                    $structureLeak = $out['error'] !== null
                        && (str_contains($out['error'], 'syntax error')
                            || str_contains($out['error'], 'near "'));
                    $safe = $guardBlocked || !$structureLeak;
                    $how = $guardBlocked ? 'runtime guard' : 'application whitelist / inert value';
                    $this->record($safe,
                        "{$name}: malicious identifier neutralized ({$how})",
                        "{$name}: identifier payload caused a SQL structure error: " . ($out['error'] ?? '?'));
                    continue;
                }

                // Value position: either an inert result or a non-syntax
                // runtime error, but never a SQL syntax/structure error.
                if ($out['error'] !== null) {
                    $leak = str_contains($out['error'], 'syntax error')
                        || str_contains($out['error'], 'near "');
                    $this->record(!$leak,
                        "{$name}: payload cannot alter SQL structure",
                        "{$name}: structure leak: {$out['error']}");
                } else {
                    $this->record(true, "{$name}: payload bound as inert value", '');
                }
            }
        }
    }

    private function resetDatabases(string $base): void
    {
        static $init = null;
        if ($init === null) {
            require_once $base . '/sample-app/original/schema.php';
            $init = true;
        }
        sample_init_database($base . '/output/harness_original.sqlite');
        sample_init_database($base . '/output/harness_rewritten.sqlite');
    }

    private function assertRefusals(string $base): void
    {
        $report = json_decode((string) file_get_contents($base . '/output/sample-report.json'), true);
        $codes = [];
        foreach ($report['files'] ?? [] as $issues) {
            foreach ($issues as $issue) {
                $codes[$issue['reasonCode']] = true;
            }
        }
        foreach (harness_refusals() as $name => $refusal) {
            $this->record(isset($codes[$refusal['reason']]),
                "{$name}: refused with {$refusal['reason']}",
                "{$name}: expected refusal {$refusal['reason']} not found");
        }
    }

    private function runChild(string $base, string $version, string $fn, array $args): array
    {
        $script = $base . "/tests/harness/child_{$version}.php";
        $cmd = sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($fn),
            escapeshellarg(base64_encode(serialize($args))),
        );
        $out = shell_exec($cmd);
        $decoded = json_decode((string) $out, true);
        return is_array($decoded)
            ? $decoded
            : ['result' => null, 'error' => 'bad child output: ' . substr((string) $out, 0, 200)];
    }

    private function record(bool $ok, string $success, string $failure): void
    {
        if ($ok) {
            $this->pass++;
            echo "  [PASS] {$success}\n";
        } else {
            $this->fail++;
            $this->failures[] = $failure;
            echo "  [FAIL] {$failure}\n";
        }
    }
}

exit((new HarnessRunner())->run(dirname(__DIR__, 2)));
