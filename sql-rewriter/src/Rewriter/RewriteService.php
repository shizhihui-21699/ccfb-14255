<?php
declare(strict_types=1);

namespace SqlRewriter\Rewriter;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use SqlRewriter\Analyzer\AnalysisEngine;
use SqlRewriter\Analyzer\FragmentDecision;
use SqlRewriter\Analyzer\IssueReason;
use SqlRewriter\Analyzer\ReconstructException;
use SqlRewriter\Analyzer\SinkSite;
use SqlRewriter\Analyzer\StructureException;
use SqlRewriter\Config;
use SqlRewriter\Issue;

/**
 * File-level driver: parse -> analyze -> rewrite sink calls -> print.
 *
 * Rewrites are applied from a call-identity map so the visitor itself
 * stays generic. Sinks that cannot be rewritten are left untouched and
 * reported as skipped Issues with a precise reason code.
 */
final class RewriteService
{
    private readonly Parser $parser;
    private readonly Standard $printer;
    private readonly Emitter $emitter;
    private readonly AnalysisEngine $engine;

    public function __construct(private readonly Config $config)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->printer = new Standard(['shortArraySyntax' => true]);
        $this->emitter = new Emitter($config);
        $this->engine = new AnalysisEngine($config);
    }

    public function engine(): AnalysisEngine
    {
        return $this->engine;
    }

    /**
     * @return array{code:string, issues:list<Issue>, rewrittenCount:int, skippedCount:int}
     */
    public function processFile(string $code, string $file): array
    {
        $stmts = $this->parser->parse($code)
            ?? throw new \RuntimeException("failed to parse {$file}");

        $collected = $this->engine->collect($stmts, $file);

        // Analyze global scope and every function body separately so each
        // gets fresh, correct taint facts.
        $globalSites = array_filter($collected['sites'], fn(SinkSite $s) => $this->isInGlobalScope($s, $collected['functions']));
        $results = $this->engine->analyzeScope($stmts, array_values($globalSites));

        foreach ($collected['functions'] as $fn) {
            // The sink wrapper functions themselves receive an opaque SQL
            // string by design; analyzing their bodies only produces false
            // "whole statement is dynamic" findings. Skip them.
            if ($this->config->isFlatSink($fn->name->toString())) {
                continue;
            }
            $fnSites = array_filter($collected['sites'], fn(SinkSite $s) => $this->insideFunction($s, $fn));
            $results = array_merge($results, $this->engine->analyzeScope($fn->stmts, array_values($fnSites)));
        }

        /** @var array<int, array{template:string, decisions:list<FragmentDecision>, sink:SinkSite}> $rewrites */
        $rewrites = [];
        $issues = [];
        foreach ($results as $result) {
            if ($result['issue'] !== null) {
                $issues[] = $result['issue'];
                continue;
            }
            $plan = $result['plan'];
            $rewrites[spl_object_id($result['site']->call)] = [
                'template' => $plan['template'],
                'decisions' => $plan['decisions'],
                'parts' => $plan['parts'],
                'hasConditions' => $plan['hasConditions'],
                'sink' => $result['site'],
            ];
            $issues[] = new Issue(
                $file,
                $result['site']->call->getStartLine(),
                $result['site']->sinkName,
                Issue::REWRITTEN,
                Issue::REWRITTEN,
                'rewritten to parameterized form (' . count($plan['decisions']) . ' bound fragment(s))',
                $plan['shape'],
                $plan['template'],
                array_map(fn(FragmentDecision $d) => [
                    'position' => $d->context->kind,
                    'context' => $d->context->detail,
                    'decision' => $d->action,
                    'detail' => $d->detail,
                ], $plan['decisions']),
            );
        }

        if ($rewrites === []) {
            return [
                'code' => $code,
                'issues' => $issues,
                'rewrittenCount' => 0,
                'skippedCount' => count(array_filter($issues, fn(Issue $i) => !$i->isRewritten() && $i->status !== Issue::STATIC_NO_CHANGE)),
            ];
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($rewrites, $this->emitter, $this->config) extends NodeVisitorAbstract {
            /** @param array<int, array{template:string, decisions:list<FragmentDecision>, sink:SinkSite}> $rewrites */
            public function __construct(
                private array $rewrites,
                private Emitter $emitter,
                private Config $config,
            ) {}

            public function leaveNode(Node $node): ?Node
            {
                if (!$node instanceof Expr\CallLike) {
                    return null;
                }
                $id = spl_object_id($node);
                if (!isset($this->rewrites[$id])) {
                    return null;
                }
                $rw = $this->rewrites[$id];
                if (!empty($rw['hasConditions'])) {
                    return $this->emitter->rewriteFragmentsCall($rw['sink'], $rw['decisions'], $rw['parts']);
                }
                return $this->emitter->rewriteCall($rw['sink'], $rw['template'], $rw['decisions']);
            }
        });
        $newStmts = $traverser->traverse($stmts);
        $newCode = $this->printer->prettyPrintFile($newStmts);

        $skipped = count(array_filter($issues, fn(Issue $i) => !$i->isRewritten() && $i->status !== Issue::STATIC_NO_CHANGE));
        return [
            'code' => $newCode,
            'issues' => $issues,
            'rewrittenCount' => count($rewrites),
            'skippedCount' => $skipped,
        ];
    }

    private function isInGlobalScope(SinkSite $site, array $functions): bool
    {
        foreach ($functions as $fn) {
            if ($this->insideFunction($site, $fn)) {
                return false;
            }
        }
        return true;
    }

    private function insideFunction(SinkSite $site, Node\Stmt\Function_ $fn): bool
    {
        $start = $fn->getStartFilePos();
        $end = $fn->getEndFilePos();
        $pos = $site->call->getStartFilePos();
        return $pos >= $start && $pos <= $end;
    }
}
