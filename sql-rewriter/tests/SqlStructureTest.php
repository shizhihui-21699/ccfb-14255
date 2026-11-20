<?php
declare(strict_types=1);

/**
 * Component-level tests for the structural SQL classifier. These run
 * without a database and pin the exact context assigned to each marker.
 */

require __DIR__ . '/../vendor/autoload.php';

use SqlRewriter\Analyzer\SegmentContext;
use SqlRewriter\Analyzer\SqlStructure;
use SqlRewriter\Analyzer\StructureException;

final class SqlStructureTest
{
    private int $pass = 0;
    private int $fail = 0;

    public function run(): int
    {
        $this->classifiesValueAfterEquals();
        $this->classifiesLikeStringLiteral();
        $this->classifiesOrderByIdentifierAndKeyword();
        $this->classifiesInList();
        $this->classifiesDynamicTableAfterFrom();
        $this->classifiesQualifiedName();
        $this->rejectsStackedStatements();
        $this->classifiesLimitAsNumeric();
        $this->classifiesGroupByColumn();
        $this->classifiesInsertValues();

        echo "SqlStructureTest: {$this->pass} passed, {$this->fail} failed\n";
        return $this->fail === 0 ? 0 : 1;
    }

    private function ctx(string $sql, int $markers): array
    {
        return (new SqlStructure())->analyze($sql, $markers);
    }

    private function assertSame(string $label, string $expected, string $actual): void
    {
        if ($expected === $actual) {
            $this->pass++;
        } else {
            $this->fail++;
            echo "  [FAIL] {$label}: expected {$expected}, got {$actual}\n";
        }
    }

    private function classifiesValueAfterEquals(): void
    {
        $c = $this->ctx('SELECT * FROM t WHERE id = __RWM0__', 1);
        $this->assertSame('equals value', SegmentContext::VALUE, $c[0]->kind);
    }

    private function classifiesLikeStringLiteral(): void
    {
        $c = $this->ctx("SELECT * FROM t WHERE name LIKE '%__RWM0__%'", 1);
        $this->assertSame('like literal', SegmentContext::STRING_LITERAL, $c[0]->kind);
        $this->assertSame('like hint', 'like', $c[0]->hint);
        $this->assertSame('like prefix', '%', $c[0]->literalPrefix);
        $this->assertSame('like suffix', '%', $c[0]->literalSuffix);
    }

    private function classifiesOrderByIdentifierAndKeyword(): void
    {
        $c = $this->ctx('SELECT * FROM t ORDER BY __RWM0__ __RWM1__', 2);
        $this->assertSame('order column', SegmentContext::IDENTIFIER, $c[0]->kind);
        $this->assertSame('order keyword', SegmentContext::KEYWORD, $c[1]->kind);
        $this->assertSame('keyword hint', 'sort_direction', $c[1]->hint);
    }

    private function classifiesInList(): void
    {
        $c = $this->ctx('SELECT * FROM t WHERE id IN (__RWM0__)', 1);
        $this->assertSame('in list', SegmentContext::IN_LIST, $c[0]->kind);
    }

    private function classifiesDynamicTableAfterFrom(): void
    {
        $c = $this->ctx('SELECT * FROM __RWM0__ WHERE x = 1', 1);
        $this->assertSame('table', SegmentContext::IDENTIFIER, $c[0]->kind);
        $this->assertSame('table hint', 'table', $c[0]->hint);
    }

    private function classifiesQualifiedName(): void
    {
        $c = $this->ctx('SELECT __RWM0__.__RWM1__ FROM t WHERE __RWM2__.id = __RWM3__', 4);
        $this->assertSame('qualified table', SegmentContext::IDENTIFIER, $c[0]->kind);
        $this->assertSame('qualified column', SegmentContext::IDENTIFIER, $c[1]->kind);
        $this->assertSame('where qualified table', SegmentContext::IDENTIFIER, $c[2]->kind);
        $this->assertSame('where value', SegmentContext::VALUE, $c[3]->kind);
    }

    private function rejectsStackedStatements(): void
    {
        try {
            $this->ctx("SELECT * FROM t WHERE n = __RWM0__; DROP TABLE t", 1);
            $this->fail++;
            echo "  [FAIL] stacked statements were not rejected\n";
        } catch (StructureException $e) {
            $this->assertSame('stacked reason', 'skip-stacked-statements', $e->reasonCode);
        }
    }

    private function classifiesLimitAsNumeric(): void
    {
        $c = $this->ctx('SELECT * FROM t LIMIT __RWM0__', 1);
        $this->assertSame('limit numeric', SegmentContext::VALUE, $c[0]->kind);
        $this->assertSame('limit hint', 'numeric', $c[0]->hint);
    }

    private function classifiesGroupByColumn(): void
    {
        $c = $this->ctx('SELECT a, COUNT(*) FROM t GROUP BY __RWM0__', 1);
        $this->assertSame('group by column', SegmentContext::IDENTIFIER, $c[0]->kind);
    }

    private function classifiesInsertValues(): void
    {
        $c = $this->ctx('INSERT INTO t (a, b) VALUES (__RWM0__, __RWM1__)', 2);
        $this->assertSame('insert value 1', SegmentContext::VALUE, $c[0]->kind);
        $this->assertSame('insert value 2', SegmentContext::VALUE, $c[1]->kind);
    }
}

exit((new SqlStructureTest())->run());
