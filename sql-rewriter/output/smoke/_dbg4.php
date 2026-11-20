<?php
require __DIR__ . '/../vendor/autoload.php';
use PhpParser\ParserFactory;
use PhpParser\Node\Expr;
use SqlRewriter\Config;
use SqlRewriter\Analyzer\{TaintTracker,FunctionSummaries,QueryFlattener,QuerySegment};

// Simplest possible: direct string literal, no variable
$code = '<?php function f(){ legacy_db_get_row("SELECT 1"); }';
$parser = (new ParserFactory())->createForNewestSupportedVersion();
$stmts = $parser->parse($code);
$cfg = Config::default();
$sums = new FunctionSummaries($cfg);
$flat = new QueryFlattener($cfg, $sums);
$tr = new TaintTracker($cfg, $sums);
$tr->analyzeStmts($stmts[0]->stmts);
$call = $stmts[0]->stmts[0]->expr;
$arg = $call->getArgs()[0]->value;
echo "arg class: ", get_class($arg), "\n";
echo "is Scalar: ", var_export($arg instanceof Expr\Scalar, true), "\n";
echo "is String_: ", var_export($arg instanceof Expr\Scalar\String_, true), "\n";
$segs = $flat->flatten($arg, $tr);
foreach ($segs as $s) { echo "kind=", $s->kind, " text=", var_export($s->text,true), "\n"; }
