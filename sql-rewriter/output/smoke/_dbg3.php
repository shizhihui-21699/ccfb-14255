<?php
require __DIR__ . '/../vendor/autoload.php';
use PhpParser\ParserFactory;
use PhpParser\Node\Expr;
use SqlRewriter\Config;
use SqlRewriter\Analyzer\{TaintTracker,FunctionSummaries,QueryFlattener,QuerySegment};

$code = '<?php function f($id){ $sql = "SELECT * FROM users WHERE id = " . $id; legacy_db_get_row($sql); }';
$parser = (new ParserFactory())->createForNewestSupportedVersion();
$stmts = $parser->parse($code);
$cfg = Config::default();
$sums = new FunctionSummaries($cfg);
$flat = new QueryFlattener($cfg, $sums);
$fn = $stmts[0];
$tr = new TaintTracker($cfg, $sums);
$tr->seedParameter($fn->params[0]->var, \SqlRewriter\Analyzer\Taint::user('p0'));
$tr->analyzeStmts($fn->stmts);
$call = $fn->stmts[1]->expr;
$segs = $flat->flatten($call->getArgs()[0]->value, $tr);
foreach ($segs as $s) {
    echo "kind=", $s->kind, " text=", var_export($s->text, true),
         " expr=", $s->expr?->getType(), " taint=", $s->taint?->describe(), "\n";
}
