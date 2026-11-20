<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use SqlRewriter\Config;
use SqlRewriter\Issue;

/**
 * One located SQL execution point and everything the engine found out
 * about it before rewriting.
 */
final class SinkSite
{
    /**
     * @param array{position:int, args:int} $spec
     */
    public function __construct(
        public readonly Expr\CallLike $call,
        public readonly string $sinkName,
        public readonly array $spec,
        public readonly bool $isMethodCall,
        public readonly ?Expr $connectionExpr,
        public readonly string $file,
    ) {}
}

/**
 * Orchestrates the whole analysis per file:
 * parse -> register functions -> flow-sensitive taint -> flatten each
 * sink's SQL argument -> structural plan -> emit decision/issue.
 */
final class AnalysisEngine
{
    private FunctionSummaries $summaries;
    private QueryFlattener $flattener;
    private RewritePlanner $planner;

    public function __construct(private readonly Config $config)
    {
        $this->summaries = new FunctionSummaries($config);
        $this->flattener = new QueryFlattener($config, $this->summaries);
        $this->planner = new RewritePlanner($config);
    }

    /**
     * @param list<Stmt> $topStmts
     * @return array{sites:list<SinkSite>, functions:list<Stmt\Function_>}
     */
    public function collect(array $topStmts, string $file): array
    {
        $functions = [];
        $sites = [];

        $finder = function (array $nodes) use (&$finder, &$functions, &$sites, $file) {
            foreach ($nodes as $node) {
                if (!$node instanceof Node) {
                    continue;
                }
                if ($node instanceof Stmt\Function_) {
                    $this->summaries->register($node);
                    $functions[] = $node;
                    // still look for sinks inside the function body
                    $finder($node->stmts);
                    continue;
                }
                if ($site = $this->asSinkSite($node, $file)) {
                    $sites[] = $site;
                }
                foreach ($node->getSubNodeNames() as $name) {
                    $sub = $node->$name;
                    if (is_array($sub)) {
                        $finder(array_filter($sub, fn($v) => $v instanceof Node));
                    } elseif ($sub instanceof Node) {
                        $finder([$sub]);
                    }
                }
            }
        };
        $finder($topStmts);

        return ['sites' => $sites, 'functions' => $functions];
    }

    private function asSinkSite(Node $node, string $file): ?SinkSite
    {
        if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = $node->name->toString();
            $spec = $this->config->sinkSpec($name);
            if ($spec !== null) {
                return new SinkSite($node, strtolower($name), $spec, false, null, $file);
            }
        }
        if ($node instanceof Expr\StaticCall && $node->name instanceof Node\Identifier) {
            $name = $node->class instanceof Node\Name ? $node->class->toString() . '::' . $node->name->name : null;
            if ($name !== null && ($spec = $this->config->sinkSpec($name)) !== null) {
                return new SinkSite($node, strtolower($name), $spec, false, $node->class, $file);
            }
        }
        if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $method = $node->name->name;
            if ($this->config->isPdoMethodSink($method)) {
                $spec = $this->config->pdoSinks[strtolower($method)];
                return new SinkSite($node, strtolower($method), $spec, true, $node->var, $file);
            }
        }
        return null;
    }

    /**
     * Analyze one scope (file-level statements or a function body) and
     * return, for every sink in it, either a rewrite plan or an Issue.
     *
     * @param list<Stmt> $stmts
     * @return list<array{site:SinkSite, plan:?array, issue:?Issue}>
     */
    public function analyzeScope(array $stmts, array $sites): array
    {
        $tracker = new TaintTracker($this->config, $this->summaries);
        $tracker->analyzeStmts($stmts);

        $results = [];
        foreach ($sites as $site) {
            $results[] = $this->analyzeSite($site, $tracker);
        }
        return $results;
    }

    private function analyzeSite(SinkSite $site, TaintTracker $tracker): array
    {
        $args = $site->call->getArgs();
        $sqlArg = $args[$site->spec['position']] ?? null;

        if ($sqlArg === null) {
            return ['site' => $site, 'plan' => null, 'issue' => $this->issue(
                $site, Issue::SKID_RECONSTRUCT, 'SQL argument missing at sink call',
            )];
        }

        // fully static SQL: no behavior change, no rewrite needed
        if ($sqlArg->value instanceof Node\Scalar\String_) {
            return ['site' => $site, 'plan' => null, 'issue' => new Issue(
                $site->file,
                $site->call->getStartLine(),
                $site->sinkName,
                Issue::STATIC_NO_CHANGE,
                Issue::STATIC_NO_CHANGE,
                'SQL is a literal string with no dynamic fragments',
                $sqlArg->value->value,
            )];
        }

        try {
            $segments = $this->flattener->flatten($sqlArg->value, $tracker);
            if (!$this->hasDynamicSegment($segments)) {
                return ['site' => $site, 'plan' => null, 'issue' => new Issue(
                    $site->file,
                    $site->call->getStartLine(),
                    $site->sinkName,
                    Issue::STATIC_NO_CHANGE,
                    Issue::STATIC_NO_CHANGE,
                    'SQL resolves to a fully static string',
                    $this->joinLiterals($segments),
                )];
            }
            $plan = $this->planner->plan($segments);
            return ['site' => $site, 'plan' => $plan, 'issue' => null];
        } catch (ReconstructException $e) {
            return ['site' => $site, 'plan' => null, 'issue' => $this->issue($site, $e->reasonCode, $e->getMessage())];
        } catch (StructureException $e) {
            return ['site' => $site, 'plan' => null, 'issue' => $this->issue($site, $e->reasonCode, $e->getMessage())];
        }
    }

    private function issue(SinkSite $site, string $reasonCode, string $explanation): Issue
    {
        return new Issue(
            $site->file,
            $site->call->getStartLine(),
            $site->sinkName,
            $reasonCode === Issue::REWRITTEN ? Issue::REWRITTEN : 'skipped',
            $reasonCode,
            $explanation,
        );
    }

    private function hasDynamicSegment(array $segments): bool
    {
        foreach ($segments as $seg) {
            if ($seg->kind !== QuerySegment::LITERAL) {
                return true;
            }
        }
        return false;
    }

    private function joinLiterals(array $segments): string
    {
        $out = '';
        foreach ($segments as $seg) {
            $out .= $seg->text ?? '';
        }
        return $out;
    }

    public function flattener(): QueryFlattener
    {
        return $this->flattener;
    }

    public function planner(): RewritePlanner
    {
        return $this->planner;
    }

    public function summaries(): FunctionSummaries
    {
        return $this->summaries;
    }
}
