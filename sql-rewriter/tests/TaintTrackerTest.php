<?php
declare(strict_types=1);

/**
 * Component tests for flow-sensitive taint tracking: superglobal sources,
 * branch merging, sanitizers and escaping helpers.
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\Node\Expr;
use SqlRewriter\Analyzer\FunctionSummaries;
use SqlRewriter\Analyzer\Taint;
use SqlRewriter\Analyzer\TaintTracker;
use SqlRewriter\Config;

final class TaintTrackerTest
{
    private int $pass = 0;
    private int $fail = 0;

    public function run(): int
    {
        $this->superglobalIsTainted();
        $this->intvalSanitizes();
        $this->escapeHelperMarksEscaped();
        $this->ifBranchJoinIsConservative();
        $this->concatenationPropagatesTaint();
        $this->numericCastIsSafe();

        echo "TaintTrackerTest: {$this->pass} passed, {$this->fail} failed\n";
        return $this->fail === 0 ? 0 : 1;
    }

    private function analyze(string $code, string $var): Taint
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse('<?php ' . $code);
        $config = Config::default();
        $summaries = new FunctionSummaries($config);
        $tracker = new TaintTracker($config, $summaries);
        $tracker->analyzeStmts($stmts);
        return $tracker->factFor(new Expr\Variable($var));
    }

    private function assertTainted(string $label, Taint $t): void
    {
        if ($t->isTainted()) {
            $this->pass++;
        } else {
            $this->fail++;
            echo "  [FAIL] {$label}: expected tainted, got {$t->describe()}\n";
        }
    }

    private function assertSafe(string $label, Taint $t): void
    {
        if (!$t->isTainted()) {
            $this->pass++;
        } else {
            $this->fail++;
            echo "  [FAIL] {$label}: expected safe, got {$t->describe()}\n";
        }
    }

    private function superglobalIsTainted(): void
    {
        $this->assertTainted('superglobal', $this->analyze('$x = $_GET["q"];', 'x'));
    }

    private function intvalSanitizes(): void
    {
        $this->assertSafe('intval', $this->analyze('$x = intval($_GET["q"]);', 'x'));
    }

    private function escapeHelperMarksEscaped(): void
    {
        $t = $this->analyze('$x = legacy_escape($_GET["q"]);', 'x');
        if ($t->isEscaped() && !$t->isTainted()) {
            $this->pass++;
        } else {
            $this->fail++;
            echo "  [FAIL] escape helper: expected escaped-not-tainted, got {$t->describe()}\n";
        }
    }

    private function ifBranchJoinIsConservative(): void
    {
        // One branch taints, the other does not: merged fact must be tainted.
        $t = $this->analyze('if ($c) { $x = $_GET["q"]; } else { $x = "safe"; }', 'x');
        $this->assertTainted('branch join', $t);
    }

    private function concatenationPropagatesTaint(): void
    {
        $this->assertTainted('concat', $this->analyze('$x = "SELECT " . $_GET["q"];', 'x'));
    }

    private function numericCastIsSafe(): void
    {
        $this->assertSafe('int cast', $this->analyze('$x = (int) $_GET["q"];', 'x'));
    }
}

exit((new TaintTrackerTest())->run());
