<?php
declare(strict_types=1);

namespace SqlRewriter\Cli;

use SqlRewriter\Config;
use SqlRewriter\Issue;
use SqlRewriter\Report\ReportWriter;
use SqlRewriter\Rewriter\RewriteService;

/**
 * Command-line driver.
 *
 * Usage:
 *   bin/rewrite run <srcDir> --out <outDir> --report <reportBase> [--config <file>]
 *   bin/rewrite analyze <srcDir> --report <reportBase> [--config <file>]   # report only, no code output
 */
final class Application
{
    private int $rewrittenTotal = 0;
    private int $skippedTotal = 0;

    public function run(array $argv): int
    {
        $args = $this->parseArgs(array_slice($argv, 1));
        $command = $args['_'][0] ?? 'run';
        $srcDir = $args['_'][1] ?? null;

        if ($srcDir === null || in_array('--help', $argv, true)) {
            $this->usage();
            return $srcDir === null ? 1 : 0;
        }
        if (!is_dir($srcDir)) {
            fwrite(STDERR, "source directory not found: {$srcDir}\n");
            return 2;
        }

        $config = isset($args['config']) ? $this->loadConfig($args['config']) : Config::default();
        $outDir = $args['out'] ?? null;
        $reportBase = $args['report'] ?? null;

        if ($command === 'run' && $outDir === null) {
            fwrite(STDERR, "run requires --out <dir>\n");
            return 2;
        }

        $service = new RewriteService($config);
        $issuesByFile = [];
        $files = $this->findPhpFiles($srcDir);

        foreach ($files as $file) {
            $relative = $this->relativePath($srcDir, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }
            try {
                $result = $service->processFile($code, $relative);
            } catch (\Throwable $e) {
                fwrite(STDERR, "ERROR on {$relative}: {$e->getMessage()}\n");
                $issuesByFile[$relative] = [new Issue(
                    $relative, 0, '<parse>', 'skipped', 'skip-reconstruct-failed',
                    'tool error: ' . $e->getMessage(),
                )];
                $this->skippedTotal++;
                continue;
            }

            $issuesByFile[$relative] = $result['issues'];
            $this->rewrittenTotal += $result['rewrittenCount'];
            $this->skippedTotal += $result['skippedCount'];

            if ($command === 'run' && $outDir !== null) {
                $target = rtrim($outDir, '/') . '/' . $relative;
                $this->ensureDir(dirname($target));
                file_put_contents($target, $result['code']);
            }
        }

        if ($reportBase !== null) {
            $this->ensureDir(dirname($reportBase));
            $writer = new ReportWriter($issuesByFile);
            file_put_contents($reportBase . '.json', $writer->toJson());
            file_put_contents($reportBase . '.md', $writer->toMarkdown());
            file_put_contents($reportBase . '.html', $writer->toHtml());
        }

        $this->printConsole($issuesByFile, $reportBase, $outDir);
        // Non-zero exit when anything needed manual review, so CI can gate.
        return $this->skippedTotal > 0 ? 3 : 0;
    }

    private function printConsole(array $issuesByFile, ?string $reportBase, ?string $outDir): void
    {
        $summary = (new ReportWriter($issuesByFile))->summary();
        echo "\n";
        echo "  SQL execution points : {$summary['total']}\n";
        echo "  parameterized        : {$summary['rewritten']}\n";
        echo "  static (untouched)   : {$summary['static']}\n";
        echo "  skipped (manual)     : {$summary['skipped']}\n\n";

        if ($summary['reasons'] !== []) {
            echo "  skip reasons:\n";
            foreach ($summary['reasons'] as $code => $count) {
                echo sprintf("    %-32s %d\n", $code, $count);
            }
            echo "\n";
        }
        if ($outDir !== null) {
            echo "  rewritten code -> {$outDir}\n";
        }
        if ($reportBase !== null) {
            echo "  reports        -> {$reportBase}.{json,md,html}\n";
        }
        echo "\n";
    }

    /**
     * @return list<string>
     */
    private function findPhpFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    private function relativePath(string $base, string $full): string
    {
        $base = rtrim(realpath($base) ?: $base, '/');
        $full = (string) (realpath($full) ?: $full);
        if (str_starts_with($full, $base . '/')) {
            return substr($full, strlen($base) + 1);
        }
        return basename($full);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    private function loadConfig(string $file): Config
    {
        $config = require $file;
        if (!$config instanceof Config) {
            throw new \RuntimeException("config file must return a " . Config::class . " instance");
        }
        return $config;
    }

    private function parseArgs(array $args): array
    {
        $out = ['_' => []];
        for ($i = 0; $i < count($args); $i++) {
            $a = $args[$i];
            if (str_starts_with($a, '--')) {
                $key = substr($a, 2);
                $next = $args[$i + 1] ?? null;
                if ($next === null || str_starts_with($next, '--')) {
                    $out[$key] = true;
                } else {
                    $out[$key] = $next;
                    $i++;
                }
            } else {
                $out['_'][] = $a;
            }
        }
        return $out;
    }

    private function usage(): void
    {
        echo <<<TXT
SQL Injection Rewriter

  bin/rewrite run <srcDir> --out <outDir> --report <reportBase> [--config <file>]
  bin/rewrite analyze <srcDir> --report <reportBase> [--config <file>]

Exit codes:
  0  all dynamic sinks rewritten (or static)
  2  bad arguments
  3  completed but one or more sinks require manual review

TXT;
    }
}
