<?php
declare(strict_types=1);

namespace SqlRewriter\Report;

use SqlRewriter\Issue;

/**
 * Aggregates Issues across files and renders them in the formats a
 * migration team needs: machine-readable JSON, reviewable Markdown,
 * and a standalone HTML dashboard.
 */
final class ReportWriter
{
    /**
     * @param array<string, list<Issue>> $issuesByFile
     */
    public function __construct(private readonly array $issuesByFile) {}

    public function summary(): array
    {
        $total = $rewritten = $skipped = $static = 0;
        $reasons = [];
        foreach ($this->issuesByFile as $issues) {
            foreach ($issues as $issue) {
                $total++;
                if ($issue->status === Issue::REWRITTEN) {
                    $rewritten++;
                } elseif ($issue->status === Issue::STATIC_NO_CHANGE) {
                    $static++;
                } else {
                    $skipped++;
                    $reasons[$issue->reasonCode] = ($reasons[$issue->reasonCode] ?? 0) + 1;
                }
            }
        }
        return compact('total', 'rewritten', 'skipped', 'static', 'reasons');
    }

    public function toJson(): string
    {
        $data = ['summary' => $this->summary(), 'files' => []];
        foreach ($this->issuesByFile as $file => $issues) {
            $data['files'][$file] = array_map(fn(Issue $i) => [
                'line' => $i->line,
                'sink' => $i->sink,
                'status' => $i->status,
                'reasonCode' => $i->reasonCode,
                'explanation' => $i->explanation,
                'originalSqlShape' => $i->originalSqlShape,
                'rewrittenSqlShape' => $i->rewrittenSqlShape,
                'segments' => $i->segments,
            ], $issues);
        }
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    public function toMarkdown(): string
    {
        $s = $this->summary();
        $md = "# SQL 改写报告\n\n";
        $md .= "| 指标 | 数量 |\n|---|---|\n";
        $md .= "| 识别到的 SQL 执行点 | {$s['total']} |\n";
        $md .= "| 成功参数化改写 | {$s['rewritten']} |\n";
        $md .= "| 纯静态语句(无需改写) | {$s['static']} |\n";
        $md .= "| 保守跳过(需人工) | {$s['skipped']} |\n\n";

        if ($s['reasons'] !== []) {
            $md .= "## 跳过原因分布\n\n| 原因码 | 次数 | 含义 |\n|---|---|---|\n";
            foreach ($s['reasons'] as $code => $count) {
                $md .= "| `{$code}` | {$count} | " . ReasonText::zh($code) . " |\n";
            }
            $md .= "\n";
        }

        foreach ($this->issuesByFile as $file => $issues) {
            $md .= "## {$file}\n\n";
            foreach ($issues as $i) {
                $icon = $i->status === Issue::REWRITTEN ? '✅'
                    : ($i->status === Issue::STATIC_NO_CHANGE ? '⚪' : '⛔');
                $md .= "- {$icon} **L{$i->line}** `{$i->sink}` — {$i->reasonCode}\n";
                $md .= "  - {$i->explanation}\n";
                if ($i->originalSqlShape !== null) {
                    $md .= "  - 原始形态: `" . $this->escapeMd($i->originalSqlShape) . "`\n";
                }
                if ($i->rewrittenSqlShape !== null) {
                    $md .= "  - 改写形态: `" . $this->escapeMd($i->rewrittenSqlShape) . "`\n";
                }
            }
            $md .= "\n";
        }
        return $md;
    }

    public function toHtml(): string
    {
        $s = $this->summary();
        $rows = '';
        foreach ($this->issuesByFile as $file => $issues) {
            foreach ($issues as $i) {
                $cls = $i->status === Issue::REWRITTEN ? 'ok'
                    : ($i->status === Issue::STATIC_NO_CHANGE ? 'static' : 'skip');
                $badge = htmlspecialchars($i->reasonCode);
                $rows .= "<tr class='{$cls}'>"
                    . "<td>" . htmlspecialchars(basename($file)) . ":{$i->line}</td>"
                    . "<td>" . htmlspecialchars($i->sink) . "</td>"
                    . "<td><span class='badge {$cls}'>{$badge}</span></td>"
                    . "<td>" . htmlspecialchars($i->explanation) . "</td>"
                    . "<td class='sql'>" . htmlspecialchars($i->originalSqlShape ?? '') . "</td>"
                    . "<td class='sql'>" . htmlspecialchars($i->rewrittenSqlShape ?? '') . "</td>"
                    . "</tr>\n";
            }
        }
        $reasonChips = '';
        foreach ($s['reasons'] as $code => $count) {
            $reasonChips .= "<span class='chip'>" . htmlspecialchars($code) . " × {$count}</span>";
        }

        return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SQL 参数化改写报告</title>
<style>
:root { color-scheme: light dark; }
body { font-family: -apple-system, "Segoe UI", sans-serif; margin: 0; padding: 24px; background: #f6f7f9; color: #1a1d21; }
@media (prefers-color-scheme: dark) { body { background: #14161a; color: #e6e8eb; } }
h1 { font-size: 20px; }
.cards { display: flex; gap: 12px; flex-wrap: wrap; margin: 16px 0; }
.card { background: #fff; border-radius: 10px; padding: 16px 20px; min-width: 130px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
@media (prefers-color-scheme: dark) { .card { background: #1f2329; } }
.card .num { font-size: 28px; font-weight: 700; }
.card .lbl { font-size: 12px; opacity: .7; }
table { border-collapse: collapse; width: 100%; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
@media (prefers-color-scheme: dark) { table { background: #1f2329; } }
th, td { text-align: left; padding: 8px 10px; font-size: 13px; border-bottom: 1px solid rgba(128,128,128,.2); vertical-align: top; }
th { background: rgba(128,128,128,.12); }
td.sql { font-family: ui-monospace, Menlo, monospace; font-size: 12px; max-width: 320px; word-break: break-all; }
.badge { padding: 2px 8px; border-radius: 999px; font-size: 11px; font-family: ui-monospace, monospace; }
.badge.ok { background: #d8f5e3; color: #0a6b35; }
.badge.skip { background: #fde2e1; color: #9b1c1c; }
.badge.static { background: #e5e7eb; color: #4b5563; }
.chip { display:inline-block; background: rgba(128,128,128,.18); border-radius: 6px; padding: 4px 10px; margin: 2px; font-size: 12px; font-family: monospace; }
</style>
</head>
<body>
<h1>SQL 参数化改写报告</h1>
<div class="cards">
  <div class="card"><div class="num">{$s['total']}</div><div class="lbl">SQL 执行点</div></div>
  <div class="card"><div class="num" style="color:#0a8a44">{$s['rewritten']}</div><div class="lbl">已参数化</div></div>
  <div class="card"><div class="num">{$s['static']}</div><div class="lbl">纯静态</div></div>
  <div class="card"><div class="num" style="color:#b42318">{$s['skipped']}</div><div class="lbl">需人工处理</div></div>
</div>
<p>{$reasonChips}</p>
<table>
<thead><tr><th>位置</th><th>Sink</th><th>状态</th><th>说明</th><th>原始形态</th><th>改写形态</th></tr></thead>
<tbody>{$rows}</tbody>
</table>
</body>
</html>
HTML;
    }

    private function escapeMd(string $s): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $s);
    }
}
