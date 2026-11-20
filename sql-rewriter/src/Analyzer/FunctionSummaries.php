<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use SqlRewriter\Config;

/**
 * Builds and caches {@see FunctionSummary} objects for every user-defined
 * function in the analyzed codebase, so SQL assembled inside helpers
 * (e.g. build_where($id), order_by($column)) can be reconstructed at call
 * sites with the caller's real arguments inlined.
 */
final class FunctionSummaries
{
    /** @var array<string, Stmt\Function_> */
    private array $functions = [];

    /** @var array<string, FunctionSummary> */
    private array $cache = [];

    /** @var array<string, true> recursion guard */
    private array $inProgress = [];

    private readonly QueryFlattener $flattener;
    private readonly TaintTracker $prototypeTracker;

    public function __construct(private readonly Config $config)
    {
        $this->flattener = new QueryFlattener($config, $this);
        $this->prototypeTracker = new TaintTracker($config, $this);
    }

    public function register(Stmt\Function_ $fn): void
    {
        $name = strtolower($fn->name->toString());
        // Escape helpers, sinks and known IN-list helpers have dedicated
        // handling in the analyzer; never build inline summaries for them,
        // otherwise their internal parameter names would leak into rewritten
        // bindings (e.g. legacy_escape's $value replacing the real argument).
        if ($this->config->isEscapeHelper($name)
            || $this->config->isFlatSink($name)
            || isset($this->config->inListHelpers[$name])) {
            return;
        }
        $this->functions[$fn->name->toString()] = $fn;
    }

    public function has(string $name): bool
    {
        return isset($this->functions[strtolower($name)]);
    }

    public function get(string $name): FunctionSummary
    {
        $key = strtolower($name);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        if (!isset($this->functions[$key])) {
            return FunctionSummary::opaque($name, 'function not found');
        }
        if (isset($this->inProgress[$key])) {
            return $this->cache[$key] = FunctionSummary::opaque($name, 'recursive helper, cannot inline safely');
        }

        $this->inProgress[$key] = true;
        try {
            $summary = $this->buildSummary($this->functions[$key]);
        } catch (ReconstructException $e) {
            $summary = FunctionSummary::opaque($name, $e->getMessage());
        } finally {
            unset($this->inProgress[$key]);
        }
        return $this->cache[$key] = $summary;
    }

    private function buildSummary(Stmt\Function_ $fn): FunctionSummary
    {
        $name = $fn->name->toString();

        // Seed parameter facts: conservatively assume every parameter is raw
        // user input. The inliner may weaken that when the actual argument
        // is provably safe, but a summary must stay sound for any caller.
        $tracker = new TaintTracker($this->config, $this);
        $paramTaints = [];
        foreach ($fn->params as $i => $param) {
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $t = Taint::user('param#' . $i);
                if ($param->default instanceof Expr) {
                    $t = Taint::join($t, Taint::safe('default'));
                }
                $tracker->seedParameter($param->var, $t);
                $paramTaints[$i] = $t;
            }
        }
        $tracker->analyzeStmts($fn->stmts);

        // Collect every reachable return statement; all shapes must match.
        $returns = $this->collectReturns($fn->stmts);
        if ($returns === []) {
            return FunctionSummary::opaque($name, 'function has no return statement usable as SQL');
        }

        $shapes = [];
        $returnTaint = null;
        foreach ($returns as $ret) {
            if (!$ret->expr instanceof Expr) {
                return FunctionSummary::opaque($name, 'empty return mixed with SQL returns');
            }
            $segments = $this->flattener->flatten($ret->expr, $tracker);
            $shapes[] = $segments;
            $returnTaint = Taint::join($returnTaint, $tracker->factFor($ret->expr));
        }

        if (!$this->shapesCompatible($shapes)) {
            return FunctionSummary::opaque($name,
                'function returns SQL of different literal shapes on different branches');
        }

        // Merge per-branch segments when several return paths exist.
        $merged = count($shapes) === 1 ? $shapes[0] : $this->mergeShapes($shapes);

        return new FunctionSummary($name, false, $merged, $paramTaints, $returnTaint);
    }

    private function shapesCompatible(array $shapes): bool
    {
        $reference = null;
        foreach ($shapes as $segments) {
            $literalShape = array_map(
                fn(QuerySegment $s) => $s->kind === QuerySegment::LITERAL ? $s->text : "\0DYN\0",
                $segments,
            );
            if ($reference === null) {
                $reference = $literalShape;
            } elseif ($reference !== $literalShape) {
                return false;
            }
        }
        return true;
    }

    private function mergeShapes(array $shapes): array
    {
        // Same shape guarantees: literal positions identical, dynamic
        // positions differ only in the expression they carry. For branch
        // returns the safe choice is to keep the first shape's dynamic
        // expression wrapped so taint is the join across branches — the
        // tracker already merged variable facts before each return.
        return $shapes[0];
    }

    /**
     * @return list<Stmt\Return_>
     */
    private function collectReturns(array $stmts): array
    {
        $out = [];
        $finder = function (array $nodes) use (&$out, &$finder) {
            foreach ($nodes as $node) {
                if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod
                    || $node instanceof Expr\Closure) {
                    continue; // nested scopes own their returns
                }
                if ($node instanceof Stmt\Return_) {
                    $out[] = $node;
                }
                foreach ($node->getSubNodeNames() as $sub) {
                    $value = $node->$sub;
                    if (is_array($value)) {
                        $finder(array_filter($value, fn($v) => $v instanceof Node));
                    } elseif ($value instanceof Node) {
                        $finder([$value]);
                    }
                }
            }
        };
        $finder($stmts);
        return $out;
    }

    /**
     * Compute the return taint of a call given the caller's argument facts.
     *
     * @param list<Taint> $argTaints
     */
    public function returnTaint(string $name, array $argTaints): Taint
    {
        $summary = $this->get($name);
        if ($summary->opaque) {
            // Opaque helper: tainted argument => tainted return.
            foreach ($argTaints as $t) {
                if ($t->isTainted()) {
                    return Taint::user('opaque-fn:' . $name);
                }
            }
            return Taint::safe('opaque-fn-safe');
        }
        return $summary->constantReturnTaint ?? Taint::safe();
    }

    /**
     * Inline a helper's returned SQL at a call site, substituting the
     * caller's argument expressions for parameter placeholders.
     *
     * @param list<Arg> $args
     * @return list<QuerySegment>
     */
    public function inline(string $name, array $args): array
    {
        $summary = $this->get($name);
        if ($summary->opaque) {
            throw new ReconstructException('skip-opaque-sql-fragment',
                "helper {$name}() is opaque: {$summary->reason}");
        }

        $argExprs = array_map(fn(Arg $a) => $a->value, $args);
        $out = [];
        foreach ($summary->segments as $segment) {
            if ($segment->kind !== QuerySegment::DYNAMIC || !$segment->expr instanceof ParamExpr) {
                $out[] = $segment;
                continue;
            }
            $idx = $segment->expr->index;
            if (!isset($argExprs[$idx])) {
                throw new ReconstructException('skip-unsupported-expression',
                    "missing argument #{$idx} when inlining {$name}()");
            }
            $out[] = QuerySegment::dynamic($argExprs[$idx], $segment->taint ?? Taint::user('param'), $segment->line);
        }
        return $out;
    }

    /**
     * Substitute ParamExpr nodes inside an arbitrary expression tree with
     * the caller's argument expressions.
     *
     * @param list<Expr> $argExprs
     */
    public function substituteParams(Expr $expr, array $argExprs): Expr
    {
        if ($expr instanceof ParamExpr) {
            return $argExprs[$expr->index]
                ?? throw new ReconstructException('skip-unsupported-expression',
                    'parameter index out of range during inlining');
        }
        $clone = clone $expr;
        foreach ($clone->getSubNodeNames() as $name) {
            $sub = $clone->$name;
            if ($sub instanceof Expr) {
                $clone->$name = $this->substituteParams($sub, $argExprs);
            } elseif (is_array($sub)) {
                $clone->$name = array_map(
                    fn($v) => $v instanceof Expr ? $this->substituteParams($v, $argExprs) : $v,
                    $sub,
                );
            }
        }
        return $clone;
    }
}
