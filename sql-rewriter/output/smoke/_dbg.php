<?php
require __DIR__ . '/../vendor/autoload.php';
use PhpParser\ParserFactory;
use SqlRewriter\Config;
use SqlRewriter\Analyzer\{TaintTracker,FunctionSummaries,QueryFlattener,RewritePlanner};

$code = file_get_contents(__DIR__.'/smoke.php');
$parser = (new ParserFactory())->createForNewestSupportedVersion();
$stmts = $parser->parse($code);
$cfg = Config::default();
$sums = new FunctionSummaries($cfg);
$flat = new QueryFlattener($cfg, $sums);
$plan = new RewritePlanner($cfg);

// find first function's sink manually
foreach ($stmts as $fn) {
    if (!$fn instanceof PhpParser\Node\Stmt\Function_) continue;
    $tr = new TaintTracker($cfg, $sums);
    foreach ($fn->params as $i=>$p) { $tr->seedParameter($p->var, \SqlRewriter\Analyzer\Taint::user("p$i")); }
    $tr->analyzeStmts($fn->stmts);
    foreach ($fn->stmts as $st) {
        if ($st instanceof PhpParser\Node\Stmt\Return_) {
            $call = $st->expr;
            $sqlArg = $call->getArgs()[0]->value;
            try {
                $segs = $flat->flatten($sqlArg, $tr);
                foreach ($segs as $s) {
                    echo $s->kind, " => ", $s->text ?? '['.($s->expr?->getType() ?? '?').']', "\n";
                }
                $p = $plan->plan($segs);
                echo "TEMPLATE: ", $p['template'], "\n\n";
            } catch (\Throwable $e) {
                echo "ERR: ", $e->getMessage(), "\n\n";
            }
        }
    }
}
