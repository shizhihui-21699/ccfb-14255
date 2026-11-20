<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use SqlRewriter\Config;

/**
 * Flow-sensitive, intra-procedural taint tracking.
 *
 * Facts form a small lattice (see Taint). Branching constructs merge facts
 * conservatively: if either branch taints a variable, the merged fact is
 * tainted. Loops are analyzed for one body pass with pre/post merge, which
 * is sound for taint (a fixed point can only add, never remove taint).
 */
final class TaintTracker
{
    private const SUPERGLOBALS = [
        '_GET' => true, '_POST' => true, '_REQUEST' => true, '_COOKIE' => true,
        '_SERVER' => true, '_ENV' => true, '_FILES' => true, 'HTTP_RAW_POST_DATA' => true,
    ];

    /** @var array<string, Taint> */
    private array $facts = [];

    /**
     * Last value expression assigned to each tracked symbol, used by the
     * QueryFlattener to follow $sql = "..."; query($sql) data flow.
     *
     * @var array<string, Expr>
     */
    private array $defExpr = [];

    /** @var array<string, true> symbols whose definition is branch-dependent */
    private array $defDiverged = [];

    /**
     * Structural SQL construction history per symbol, populated for variables
     * that are built by assignment/append of SQL-shaped strings.
     *
     * @var array<string, SqlBuildState>
     */
    private array $buildStates = [];

    public function __construct(
        private readonly Config $config,
        private readonly ?FunctionSummaries $summaries = null,
    ) {}

    public function reset(): void
    {
        $this->facts = [];
    }

    /**
     * Seed a parameter fact (used when analyzing a function body in
     * isolation). Parameters are conservatively treated as user input.
     */
    public function seedParameter(Expr\Variable $var, Taint $taint): void
    {
        $this->store($var, $taint);
    }

    public function factFor(Expr $expr): Taint
    {
        return $this->evalExpr($expr);
    }

    // ---------------------------------------------------------------- statements

    public function analyzeStmts(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            $this->visitStmt($stmt);
        }
    }

    private function visitStmt(Stmt $stmt): void
    {
        if ($stmt instanceof Stmt\If_) {
            $this->evalExpr($stmt->cond);
            $before = $this->snapshot();

            $this->analyzeStmts($stmt->stmts);
            $branchTables = [$this->facts];
            $branchDefs = [$this->defState()];
            $thenBuild = $this->snapshotBuilds();

            $elseifBuilds = [];
            foreach ($stmt->elseifs as $elseif) {
                $this->restore($before);
                $this->evalExpr($elseif->cond);
                $this->analyzeStmts($elseif->stmts);
                $branchTables[] = $this->facts;
                $branchDefs[] = $this->defState();
                $elseifBuilds[] = ['cond' => $elseif->cond, 'builds' => $this->snapshotBuilds()];
            }

            if ($stmt->else !== null) {
                $this->restore($before);
                $this->analyzeStmts($stmt->else->stmts);
            } else {
                $this->restore($before);
            }
            $branchTables[] = $this->facts;
            $branchDefs[] = $this->defState();
            $elseBuild = $this->snapshotBuilds();

            $this->facts = $this->mergeFactTables($branchTables, $branchDefs);
            $this->mergeBuildStates($before, $thenBuild, $elseifBuilds, $elseBuild, $stmt);
            return;
        }

        if ($stmt instanceof Stmt\Switch_) {
            $this->evalExpr($stmt->cond);
            $before = $this->snapshot();
            $branchTables = [$this->facts];
            $branchDefs = [$this->defState()];
            foreach ($stmt->cases as $case) {
                if ($case->cond !== null) {
                    $this->evalExpr($case->cond);
                }
                $this->analyzeStmts($case->stmts);
                $branchTables[] = $this->facts;
                $branchDefs[] = $this->defState();
                $this->restore($before);
            }
            $this->facts = $this->mergeFactTables($branchTables, $branchDefs);
            return;
        }

        if ($stmt instanceof Stmt\While_ || $stmt instanceof Stmt\Do_) {
            $before = $this->snapshot();
            $this->evalExpr($stmt->cond);
            $this->analyzeStmts($stmt->stmts);
            $this->facts = $this->mergeFactTables([$before['facts'], $this->facts],
                [$this->defStateFrom($before), $this->defState()]);
            return;
        }

        if ($stmt instanceof Stmt\Foreach_) {
            $this->evalExpr($stmt->expr);
            $before = $this->snapshot();
            $iterKey = $this->symbolKey($stmt->valueVar);
            $iterTaint = $this->evalExpr($stmt->expr);
            if ($iterKey !== null) {
                $this->facts[$iterKey] = $iterTaint;
                unset($this->defExpr[$iterKey], $this->defDiverged[$iterKey]);
            }
            if ($stmt->keyVar !== null && ($kk = $this->symbolKey($stmt->keyVar)) !== null) {
                $this->facts[$kk] = $iterTaint;
                unset($this->defExpr[$kk], $this->defDiverged[$kk]);
            }
            $this->analyzeStmts($stmt->stmts);
            $this->facts = $this->mergeFactTables([$before['facts'], $this->facts],
                [$this->defStateFrom($before), $this->defState()]);
            return;
        }

        if ($stmt instanceof Stmt\For_) {
            $before = $this->snapshot();
            foreach ($stmt->init as $e) {
                $this->evalExpr($e);
            }
            foreach ($stmt->cond as $e) {
                $this->evalExpr($e);
            }
            foreach ($stmt->loop as $e) {
                $this->evalExpr($e);
            }
            $this->analyzeStmts($stmt->stmts);
            $this->facts = $this->mergeFactTables([$before['facts'], $this->facts],
                [$this->defStateFrom($before), $this->defState()]);
            return;
        }

        if ($stmt instanceof Stmt\TryCatch) {
            $before = $this->snapshot();
            $this->analyzeStmts($stmt->stmts);
            $branchTables = [$this->facts];
            $branchDefs = [$this->defState()];
            foreach ($stmt->catches as $catch) {
                $this->restore($before);
                if (($k = $this->symbolKey($catch->var)) !== null) {
                    $this->facts[$k] = Taint::safe('exception');
                    unset($this->defExpr[$k], $this->defDiverged[$k]);
                }
                $this->analyzeStmts($catch->stmts);
                $branchTables[] = $this->facts;
                $branchDefs[] = $this->defState();
            }
            $this->facts = $this->mergeFactTables($branchTables, $branchDefs);
            if ($stmt->finally !== null) {
                $this->analyzeStmts($stmt->finally->stmts);
            }
            return;
        }

        if ($stmt instanceof Stmt\Expression_) {
            $this->evalExpr($stmt->expr);
            return;
        }

        if ($stmt instanceof Stmt\Return_ || $stmt instanceof Stmt\Throw_ || $stmt instanceof Stmt\Global_
            || $stmt instanceof Stmt\Static_ || $stmt instanceof Stmt\Unset_ || $stmt instanceof Stmt\Declare_
            || $stmt instanceof Stmt\InlineHTML || $stmt instanceof Stmt\Nop) {
            if (method_exists($stmt, 'getExpr') && $stmt->expr instanceof Expr) {
                $this->evalExpr($stmt->expr);
            }
            if ($stmt instanceof Stmt\Unset_) {
                foreach ($stmt->vars as $v) {
                    if (($k = $this->symbolKey($v)) !== null) {
                        unset($this->facts[$k]);
                    }
                }
            }
            return;
        }

        if ($stmt instanceof Stmt\Function_ || $stmt instanceof Stmt\Class_ || $stmt instanceof Stmt\Interface_
            || $stmt instanceof Stmt\Trait_ || $stmt instanceof Stmt\Enum_) {
            return; // analyzed separately via FunctionSummaries
        }

        // fall-back: visit sub-expressions so nested assignments are not missed
        foreach ($stmt->getSubNodeNames() as $name) {
            $sub = $stmt->$name;
            if ($sub instanceof Expr) {
                $this->evalExpr($sub);
            } elseif (is_array($sub)) {
                array_walk_recursive($sub, function ($n) {
                    if ($n instanceof Expr) {
                        $this->evalExpr($n);
                    }
                });
            }
        }
    }

    // ------------------------------------------------------------- expressions

    public function evalExpr(Expr $expr): Taint
    {
        // direct variable / array / property read
        if ($expr instanceof Expr\Variable) {
            if (is_string($expr->name) && isset(self::SUPERGLOBALS[$expr->name])) {
                return Taint::user('superglobal:' . $expr->name);
            }
            $key = $this->symbolKey($expr);
            return $key !== null ? ($this->facts[$key] ?? Taint::safe('uninitialized')) : Taint::safe();
        }

        if ($expr instanceof Expr\ArrayDimFetch) {
            $key = $this->symbolKey($expr);
            if ($key !== null && array_key_exists($key, $this->facts)) {
                return $this->facts[$key];
            }
            return $this->evalExpr($expr->var); // element inherits container taint
        }

        if ($expr instanceof Expr\PropertyFetch || $expr instanceof Expr\StaticPropertyFetch) {
            $key = $this->symbolKey($expr);
            return $key !== null ? ($this->facts[$key] ?? Taint::safe('unknown-property')) : Taint::safe();
        }

        // literals / constants
        if ($expr instanceof Node\Scalar) {
            return Taint::safe('literal');
        }
        if ($expr instanceof Expr\ConstFetch || $expr instanceof Expr\ClassConstFetch) {
            return Taint::safe('const');
        }

        // assignments
        if ($expr instanceof Expr\Assign) {
            $rhs = $this->evalExpr($expr->expr);
            $this->store($expr->var, $rhs, $expr->expr);
            $this->recordBuildFromAssign($expr->var, $expr->expr);
            return $rhs;
        }
        if ($expr instanceof Expr\AssignRef) {
            $rhs = $this->evalExpr($expr->expr);
            $this->store($expr->var, $rhs, $expr->expr);
            return $rhs;
        }
        if ($expr instanceof Expr\AssignOp) {
            $rhs = $this->evalExpr($expr->expr);
            $lhsKey = $this->symbolKey($expr->var);
            $lhs = $lhsKey !== null ? ($this->facts[$lhsKey] ?? Taint::safe()) : Taint::safe();
            $merged = Taint::join($lhs, $rhs);
            if ($lhsKey !== null) {
                $this->facts[$lhsKey] = $merged;
                // Remember the previous definition on the node itself so the
                // flattener can walk the assignment chain ($sql = ...; $sql .= ...)
                // without re-expanding this node and recursing.
                $expr->setAttribute('rwPriorDef', $this->defExpr[$lhsKey] ?? null);
                $this->defExpr[$lhsKey] = $expr;
                unset($this->defDiverged[$lhsKey]);
                if ($expr instanceof Expr\AssignOp\Concat) {
                    $this->recordBuildFromAppend($expr->var, $expr->expr);
                }
            }
            return $merged;
        }

        // casts
        if ($expr instanceof Expr\Cast\Int_ || $expr instanceof Expr\Cast\Double
            || $expr instanceof Expr\Cast\Bool_) {
            $this->evalExpr($expr->expr);
            return Taint::safe('numeric-cast');
        }
        if ($expr instanceof Expr\Cast\String_) {
            return $this->evalExpr($expr->expr);
        }
        if ($expr instanceof Expr\Cast\Array_) {
            return $this->evalExpr($expr->expr);
        }

        // binary
        if ($expr instanceof Expr\BinaryOp) {
            $l = $this->evalExpr($expr->left);
            $r = $this->evalExpr($expr->right);
            if ($expr instanceof Expr\BinaryOp\Concat) {
                return Taint::join($l, $r);
            }
            if ($expr instanceof Expr\BinaryOp\Plus || $expr instanceof Expr\BinaryOp\Minus
                || $expr instanceof Expr\BinaryOp\Mul || $expr instanceof Expr\BinaryOp\Div
                || $expr instanceof Expr\BinaryOp\Mod || $expr instanceof Expr\BinaryOp\Pow
                || $expr instanceof Expr\BinaryOp\BitwiseAnd || $expr instanceof Expr\BinaryOp\BitwiseOr
                || $expr instanceof Expr\BinaryOp\BitwiseXor || $expr instanceof Expr\BinaryOp\ShiftLeft
                || $expr instanceof Expr\BinaryOp\ShiftRight) {
                // arithmetic on strings can still carry tainted text in PHP,
                // so treat the result as tainted unless both sides are safe
                return Taint::join($l, $r);
            }
            return Taint::safe('comparison');
        }

        if ($expr instanceof Expr\UnaryMinus || $expr instanceof Expr\UnaryPlus) {
            return $this->evalExpr($expr->expr);
        }
        if ($expr instanceof Expr\BooleanNot) {
            $this->evalExpr($expr->expr);
            return Taint::safe('bool');
        }

        // null coalescing must be checked before BinaryOp (it is a BinaryOp subtype)
        if ($expr instanceof Expr\BinaryOp\Coalesce) {
            $this->evalExpr($expr->left);
            $a = $this->evalExpr($expr->left);
            $b = $this->evalExpr($expr->right);
            return Taint::join($a, $b);
        }

        if ($expr instanceof Expr\Ternary) {
            $this->evalExpr($expr->cond);
            $a = $expr->if !== null ? $this->evalExpr($expr->if) : $this->evalExpr($expr->cond);
            $b = $this->evalExpr($expr->else);
            return Taint::join($a, $b);
        }

        if ($expr instanceof Expr\Match_) {
            $this->evalExpr($expr->cond);
            $arms = [];
            foreach ($expr->arms as $arm) {
                foreach ($arm->conds ?? [] as $c) {
                    $this->evalExpr($c);
                }
                $arms[] = $this->evalExpr($arm->body);
            }
            return array_reduce($arms, Taint::join(...), Taint::safe('match'));
        }

        // interpolation: "x $v y"
        if ($expr instanceof Node\Scalar\Encapsed) {
            $t = Taint::safe('encapsed');
            foreach ($expr->parts as $part) {
                $t = Taint::join($t, $this->evalExpr($part));
            }
            return $t;
        }
        if ($expr instanceof Node\Scalar\EncapsedStringPart) {
            return Taint::safe('literal');
        }

        if ($expr instanceof Expr\Array_) {
            $t = Taint::safe('array');
            foreach ($expr->items as $item) {
                if ($item?->key !== null) {
                    $this->evalExpr($item->key);
                }
                $t = Taint::join($t, $this->evalExpr($item->value));
            }
            return $t;
        }

        if ($expr instanceof Expr\Clone_) {
            return $this->evalExpr($expr->expr);
        }
        if ($expr instanceof Expr\Instanceof_) {
            $this->evalExpr($expr->expr);
            return Taint::safe('bool');
        }
        if ($expr instanceof Expr\Isset_) {
            foreach ($expr->vars as $v) {
                $this->evalExpr($v);
            }
            return Taint::safe('bool');
        }
        if ($expr instanceof Expr\Empty_) {
            // v5: Empty_ carries a single 'expr', not a 'vars' list
            $this->evalExpr($expr->expr);
            return Taint::safe('bool');
        }

        if ($expr instanceof Expr\CallLike) {
            return $this->evalCall($expr);
        }

        if ($expr instanceof Expr\Closure || $expr instanceof Expr\ArrowFunction) {
            return Taint::safe('closure');
        }
        if ($expr instanceof Expr\New_) {
            foreach ($expr->getArgs() as $arg) {
                $this->evalExpr($arg->value);
            }
            return Taint::safe('object');
        }
        if ($expr instanceof Expr\Yield_ || $expr instanceof Expr\YieldFrom) {
            return Taint::safe('generator');
        }

        return Taint::safe('unknown-expr');
    }

    private function evalCall(Expr\CallLike $call): Taint
    {
        $args = $call->getArgs();
        foreach ($args as $arg) {
            $this->evalExpr($arg->value);
        }

        $name = $this->callName($call);

        if ($name !== null && $this->config->isEscapeHelper($name)) {
            $pos = $this->config->escapeArgPosition($name) ?? 0;
            $target = $args[$pos]->value ?? null;
            if ($target !== null && $this->evalExpr($target)->isTainted()) {
                return Taint::escaped('helper:' . $name);
            }
            return Taint::safe('escaped-literal');
        }

        if ($name !== null && Sanitizers::isSanitizer($name)) {
            return Taint::safe('sanitizer:' . $name);
        }

        if ($name !== null && isset($this->config->inListHelpers[strtolower($name)])) {
            // helpers returning "(1,2,3)" style lists keep element taint
            $spec = $this->config->inListHelpers[strtolower($name)];
            $target = $args[$spec['valueArg']]->value ?? null;
            return $target !== null ? $this->evalExpr($target) : Taint::safe();
        }

        // user-defined helper: use the cached summary
        if ($name !== null && $this->summaries !== null && $this->summaries->has($name)) {
            return $this->summaries->returnTaint($name, array_map(fn(Arg $a) => $this->evalExpr($a->value), $args));
        }

        // unknown function: taint propagates from any tainted argument
        foreach ($args as $arg) {
            if ($this->evalExpr($arg->value)->isTainted()) {
                return Taint::user('unknown-fn:' . ($name ?? 'callable'));
            }
        }
        return Taint::safe('unknown-fn');
    }

    // ------------------------------------------------------------------ storage

    private function store(Expr $var, Taint $t, ?Expr $valueExpr = null): void
    {
        $key = $this->symbolKey($var);
        if ($key === null) {
            return;
        }
        $this->facts[$key] = $t;
        if ($valueExpr !== null) {
            $this->defExpr[$key] = $valueExpr;
            unset($this->defDiverged[$key]);
        }
    }

    /**
     * Returns the expression defining $expr when the variable has a single
     * statically-visible definition; null when undefined or branch-merged.
     */
    public function assignedExpression(Expr $expr): ?Expr
    {
        $key = $this->symbolKey($expr);
        if ($key === null || isset($this->defDiverged[$key])) {
            return null;
        }
        return $this->defExpr[$key] ?? null;
    }

    /**
     * True when the symbol's definition was merged from structurally
     * different branches (so no single expression can represent it).
     */
    public function isDiverged(Expr $expr): bool
    {
        $key = $this->symbolKey($expr);
        return $key !== null && isset($this->defDiverged[$key]);
    }

    /**
     * Structural SQL build history for a symbol, when one is available.
     */
    public function buildState(Expr $expr): ?SqlBuildState
    {
        $key = $this->symbolKey($expr);
        return $key !== null ? ($this->buildStates[$key] ?? null) : null;
    }

    /**
     * Decompose an assignment RHS into ordered build steps.
     * Only string-shaped concatenations produce a build state; anything
     * else clears it so the expression-based flattener takes over.
     */
    private function recordBuildFromAssign(Expr $var, Expr $rhs): void
    {
        $key = $this->symbolKey($var);
        if ($key === null) {
            return;
        }
        $state = $this->buildStateFromExpr($rhs);
        if ($state !== null) {
            $this->buildStates[$key] = $state;
        } else {
            unset($this->buildStates[$key]);
        }
    }

    private function recordBuildFromAppend(Expr $var, Expr $rhs): void
    {
        $key = $this->symbolKey($var);
        if ($key === null) {
            return;
        }
        // Clone so snapshots taken before a branch still see the pre-append
        // history; SqlBuildState is treated as immutable across branches.
        if (!isset($this->buildStates[$key])) {
            // No build history means this variable is not a tracked SQL
            // statement (e.g. a value being concatenated for other purposes);
            // leave it to the expression-based flattener.
            return;
        }
        $state = clone $this->buildStates[$key];
        foreach ($this->stepsFromExpr($rhs) as $step) {
            $state->appendStep($step);
        }
        $this->buildStates[$key] = $state;
    }

    private function buildStateFromExpr(Expr $expr): ?SqlBuildState
    {
        // A build history is created for literal SQL text or a SQL-shaped
        // concatenation. Plain value variables ($dir = 'DESC') also get a
        // single-step history, but that is harmless: they only influence code
        // generation if they are later appended to a statement with .= and
        // reach a sink as SQL.
        $steps = $this->stepsFromExpr($expr);
        if ($steps === []) {
            return null;
        }
        $state = new SqlBuildState();
        foreach ($steps as $step) {
            $state->appendStep($step);
        }
        return $state;
    }

    /**
     * @return list<BuildStep>
     */
    private function stepsFromExpr(Expr $expr): array
    {
        if ($expr instanceof Node\Scalar\String_) {
            return [new LiteralStep($expr->value)];
        }
        if ($expr instanceof Expr\BinaryOp\Concat) {
            return array_merge($this->stepsFromExpr($expr->left), $this->stepsFromExpr($expr->right));
        }
        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name
            && in_array(strtolower($expr->name->toString()), ['implode', 'join'], true)) {
            $args = $expr->getArgs();
            if (count($args) === 2) {
                $glue = $this->staticValue($args[0]->value);
                if ($glue !== null) {
                    return [new InListStep($args[1]->value, $this->evalExpr($args[1]->value), $glue)];
                }
            }
            return [];
        }
        if ($expr instanceof Node\Scalar) {
            return [new LiteralStep((string) $expr->value)];
        }
        // any other expression is a single dynamic value step
        return [new ValueStep($expr, $this->evalExpr($expr))];
    }

    public function symbolKey(Expr $expr): ?string
    {
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            return 'v:' . $expr->name;
        }
        if ($expr instanceof Expr\ArrayDimFetch) {
            $base = $this->symbolKey($expr->var);
            if ($base === null) {
                return null;
            }
            if ($expr->dim === null) {
                return $base . '[]';
            }
            $dim = $this->staticValue($expr->dim);
            return $base . '[' . ($dim ?? '?') . ']';
        }
        if ($expr instanceof Expr\PropertyFetch && is_string($expr->name?->name ?? null)) {
            return 'p:' . $this->symbolKey($expr->var) . '->' . $expr->name->name;
        }
        if ($expr instanceof Expr\StaticPropertyFetch && is_string($expr->name?->name ?? null)) {
            return 'sp:' . $expr->class . '::$' . $expr->name->name;
        }
        return null;
    }

    public function staticValue(Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_ || $expr instanceof Node\Scalar\LNumber
            || $expr instanceof Node\Scalar\DNumber) {
            return (string) $expr->value;
        }
        if ($expr instanceof Expr\ConstFetch) {
            return strtolower($expr->name->toString());
        }
        if ($expr instanceof Expr\UnaryMinus && $expr->expr instanceof Node\Scalar\LNumber) {
            return '-' . $expr->expr->value;
        }
        return null;
    }

    private function callName(Expr\CallLike $call): ?string
    {
        if ($call instanceof Expr\FuncCall && $call->name instanceof Node\Name) {
            return $call->name->toString();
        }
        if ($call instanceof Expr\StaticCall && $call->name instanceof Node\Identifier) {
            $class = $call->class instanceof Node\Name ? $call->class->toString() : null;
            return $class !== null ? $class . '::' . $call->name->name : null;
        }
        if ($call instanceof Expr\MethodCall && $call->name instanceof Node\Identifier) {
            return 'method:' . $call->name->name;
        }
        return null;
    }

    /**
     * @param list<array<string, Taint>> $tables
     * @param list<array{exprs:array<string, Expr>, diverged:array<string, true>}> $defStates
     * @return array<string, Taint>
     */
    private function mergeFactTables(array $tables, array $defStates = []): array
    {
        $this->preMergeExprs = $this->defExpr;
        $this->preMergeDiverged = $this->defDiverged;
        $keys = [];
        foreach ($tables as $table) {
            foreach (array_keys($table) as $k) {
                $keys[$k] = true;
            }
        }
        $out = [];
        $preState = ['exprs' => $this->preMergeExprs, 'diverged' => $this->preMergeDiverged];
        foreach (array_keys($keys) as $k) {
            $merged = null;
            foreach ($tables as $table) {
                if (array_key_exists($k, $table)) {
                    $merged = Taint::join($merged, $table[$k]);
                }
            }
            $out[$k] = $merged ?? Taint::safe();

            if ($defStates !== []) {
                $this->mergeDefinition($k, $defStates, count($tables), $preState);
            }
        }
        return $out;
    }

    private array $preMergeExprs = [];
    private array $preMergeDiverged = [];

    /**
     * @param list<array{exprs:array<string, Expr>, diverged:array<string, true>}> $defStates
     * @param array{exprs:array<string, Expr>, diverged:array<string, true>} $preState
     */
    private function mergeDefinition(string $key, array $defStates, int $branchCount, array $preState): void
    {
        // Build the *effective* definition per branch: a branch that never
        // assigned the symbol effectively keeps the pre-branch definition.
        // Only genuinely different effective definitions mark divergence.
        $effective = [];
        $anyDiverged = false;
        foreach ($defStates as $state) {
            if (isset($state['diverged'][$key])) {
                $anyDiverged = true;
                continue;
            }
            if (array_key_exists($key, $state['exprs'])) {
                $effective[] = $state['exprs'][$key];
            } else {
                // Untouched branch: its effective definition is exactly the
                // pre-branch one. Recording it keeps append-chain merges
                // (BASE; if (...) $sql .= X;) from looking like a conflict.
                if (isset($preState['diverged'][$key])) {
                    $anyDiverged = true;
                } elseif (array_key_exists($key, $preState['exprs'])) {
                    $effective[] = $preState['exprs'][$key];
                }
            }
        }

        if ($anyDiverged || isset($preState['diverged'][$key])) {
            $this->defDiverged[$key] = true;
            unset($this->defExpr[$key]);
            return;
        }

        // All effective definitions identical -> single definition survives.
        $first = $effective[0] ?? null;
        $allSame = $first !== null && array_all($effective, fn($e) => $e === $first);
        if ($allSame) {
            $this->defExpr[$key] = $first;
            unset($this->defDiverged[$key]);
            return;
        }

        // Different definitions across branches. Reconcile append chains:
        // every effective definition must either be the pre-branch definition
        // or an AssignOp whose rwPriorDef chain is rooted in it. When several
        // *sequential* if-blocks each append, their merges happen one at a
        // time, so here we keep the longest (deepest) append chain while the
        // untouched path keeps the shared prior.
        $prior = $preState['exprs'][$key] ?? null;
        if ($prior !== null && $this->allAppendFromSamePrior($effective, $prior)) {
            $deepest = $prior;
            foreach ($effective as $e) {
                if ($e instanceof Expr\AssignOp && $this->chainDepth($e) > $this->chainDepth($deepest)) {
                    $deepest = $e;
                }
            }
            $this->defExpr[$key] = $deepest;
            unset($this->defDiverged[$key]);
            return;
        }

        $this->defDiverged[$key] = true;
        unset($this->defExpr[$key]);
    }

    private function chainDepth(Expr $expr): int
    {
        $depth = 0;
        $cur = $expr;
        while ($cur instanceof Expr\AssignOp) {
            $depth++;
            $cur = $cur->getAttribute('rwPriorDef');
            if ($depth > 128) {
                break;
            }
        }
        return $depth;
    }

    private function allAppendFromSamePrior(array $effective, ?Expr $prior): bool
    {
        if ($prior === null) {
            return false;
        }
        foreach ($effective as $e) {
            // The untouched branch contributes the prior definition itself.
            if ($e === $prior) {
                continue;
            }
            // Every other effective definition must be an append whose chain
            // is rooted in the same pre-branch definition.
            if (!$e instanceof Expr\AssignOp) {
                return false;
            }
            if ($e->getAttribute('rwPriorDef') !== $prior) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array{facts:array<string, Taint>, exprs:array<string, Expr>, diverged:array<string, true>}
     */
    private function snapshot(): array
    {
        return [
            'facts' => $this->facts,
            'exprs' => $this->defExpr,
            'diverged' => $this->defDiverged,
            'builds' => $this->buildStates,
        ];
    }

    /**
     * @param array{facts:array<string, Taint>, exprs:array<string, Expr>, diverged:array<string, true>, builds?:array<string, SqlBuildState>} $snap
     */
    private function restore(array $snap): void
    {
        $this->facts = $snap['facts'];
        $this->defExpr = $snap['exprs'];
        $this->defDiverged = $snap['diverged'];
        $this->buildStates = $snap['builds'] ?? [];
    }

    /** @return array<string, SqlBuildState> */
    private function snapshotBuilds(): array
    {
        return $this->buildStates;
    }

    /**
     * Reconcile SQL build histories across an if/elseif/else.
     *
     * The pre-if build state is the shared prefix. Each branch that appended
     * steps contributes them as an OptionalStep guarded by that branch's
     * condition. The else path (or no else) contributes nothing extra.
     *
     * @param array<int, array{cond:Expr, builds:array<string, SqlBuildState>}> $elseifBuilds
     * @param array<string, SqlBuildState> $elseBuild
     */
    private function mergeBuildStates(
        array $before,
        array $thenBuild,
        array $elseifBuilds,
        array $elseBuild,
        Stmt\If_ $if,
    ): void {
        /** @var array<string, SqlBuildState> $baseBuilds */
        $baseBuilds = $before['builds'] ?? [];
        $allKeys = array_merge(
            array_keys($baseBuilds),
            array_keys($thenBuild),
            array_keys($elseBuild),
        );
        foreach ($elseifBuilds as $b) {
            $allKeys = array_merge($allKeys, array_keys($b['builds']));
        }
        $keys = array_unique($allKeys);

        foreach ($keys as $key) {
            $base = $baseBuilds[$key] ?? new SqlBuildState();

            $thenTail = $this->tailSteps($base, $thenBuild[$key] ?? null);
            $elseifTails = [];
            foreach ($elseifBuilds as $ei) {
                $elseifTails[] = $this->tailSteps($base, $ei['builds'][$key] ?? null);
            }
            $elseTail = $this->tailSteps($base, $elseBuild[$key] ?? null);

            // Shape divergence: when there is no shared base statement and
            // branches each build their own complete statement (e.g. different
            // SELECT columns inside if/else), the result is not a single
            // parameterizable statement. Mark the symbol diverged so the
            // flattener refuses instead of silently changing semantics.
            $allTails = array_merge([$thenTail], $elseifTails, [$elseTail]);
            $nonEmptyTails = array_filter($allTails, fn($t) => $t !== []);
            if ($base->isEmpty() && count($nonEmptyTails) >= 1 && $this->tailStartsStatement($nonEmptyTails)) {
                $this->defDiverged[$key] = true;
                unset($this->buildStates[$key], $this->defExpr[$key]);
                continue;
            }

            $merged = clone $base;
            if ($thenTail !== []) {
                $merged->appendStep(new OptionalStep($if->cond, $thenTail));
            }
            foreach ($elseifBuilds as $i => $ei) {
                if ($elseifTails[$i] !== []) {
                    $merged->appendStep(new OptionalStep($ei['cond'], $elseifTails[$i]));
                }
            }
            // else branch: its tail is taken when all conditions false.
            if ($elseTail !== []) {
                $merged->appendStep(new OptionalStep(null, $elseTail));
            }

            if (!$merged->isEmpty()) {
                $this->buildStates[$key] = $merged;
                // A structural history means the symbol is no longer diverged
                // from the flattener's point of view.
                unset($this->defDiverged[$key]);
            }
        }
    }

    /**
     * A tail "starts a statement" when its first step is a literal that opens
     * a new SQL statement (SELECT/INSERT/UPDATE/...) rather than appending a
     * clause to an existing one.
     *
     * @param list<list<BuildStep>> $tails
     */
    private function tailStartsStatement(array $tails): bool
    {
        $starters = '/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE|WITH)\b/i';
        foreach ($tails as $tail) {
            $first = $tail[0] ?? null;
            if ($first instanceof LiteralStep && preg_match($starters, $first->text)) {
                return true;
            }
            // a tail whose first step is a value/identifier with no leading
            // literal clause text is also a fresh statement, not an append
            if ($first !== null && !$first instanceof LiteralStep) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<BuildStep> steps present in $branch beyond the shared base
     */
    private function tailSteps(SqlBuildState $base, ?SqlBuildState $branch): array
    {
        if ($branch === null || $branch === $base) {
            return [];
        }
        $baseCount = count($base->steps());
        return array_slice($branch->steps(), $baseCount);
    }

    private function defState(): array
    {
        return ['exprs' => $this->defExpr, 'diverged' => $this->defDiverged];
    }

    private function defStateFrom(array $snapshot): array
    {
        return ['exprs' => $snapshot['exprs'], 'diverged' => $snapshot['diverged']];
    }
}
