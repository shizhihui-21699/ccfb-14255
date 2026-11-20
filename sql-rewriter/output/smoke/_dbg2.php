<?php
require __DIR__ . '/../vendor/autoload.php';
use PhpParser\ParserFactory;
use PhpParser\Node\Expr;
use SqlRewriter\Config;
use SqlRewriter\Analyzer\{TaintTracker,FunctionSummaries,QuerySegment};

$code = '<?php function f($id){ $sql = "SELECT * FROM users WHERE id = " . $id; legacy_db_get_row($sql); }';
$parser = (new ParserFactory())->createForNewestSupportedVersion();
$stmts = $parser->parse($code);
$cfg = Config::default();
$sums = new FunctionSummaries($cfg);
$fn = $stmts[0];
$tr = new TaintTracker($cfg, $sums);
$tr->seedParameter($fn->params[0]->var, \SqlRewriter\Analyzer\Taint::user('p0'));
$tr->analyzeStmts($fn->stmts);

// find the sink call
foreach ($fn->stmts as $st) {
    if ($st instanceof Expr\FuncCall) {}
}
$call = $fn->stmts[1]->expr;
$sqlVar = $call->getArgs()[0]->value;
echo "sqlVar type: ", $sqlVar->getType(), " name=", $sqlVar->name ?? '?', "\n";
$def = $tr->assignedExpression($sqlVar);
echo "def type: ", $def?->getType(), "\n";
echo "def is concat: ", ($def instanceof Expr\BinaryOp\Concat ? 'yes' : 'no'), "\n";
if ($def instanceof Expr\BinaryOp\Concat) {
    echo "left: ", $def->left->getType(), " right: ", $def->right->getType(), "\n";
}
