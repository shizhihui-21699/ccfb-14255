<?php
declare(strict_types=1);

namespace SqlRewriter\Analyzer;

/**
 * Lenient SQL tokenizer + structural classifier.
 *
 * Given a SQL string where every dynamic PHP fragment has been replaced by
 * a placeholder of the form __RWM<n>__, it decides which syntactic position
 * each placeholder occupies. It deliberately understands only the small
 * subset of SQL grammar needed for that decision; anything it cannot
 * classify confidently is reported as UNKNOWN so the caller can refuse to
 * rewrite rather than guess.
 */
final class SqlStructure
{
    private const MARKER_RE = '/^__RWM(\d+)__/';

    /**
     * @return array<int, SegmentContext> keyed by placeholder index
     *
     * @throws StructureException when the shape cannot be handled safely
     */
    public function analyze(string $sql, int $markerCount): array
    {
        $tokens = $this->tokenize($sql);
        $this->assertNoStackedStatements($tokens);

        $contexts = [];
        $parenStack = [];
        $clause = '';
        $pendingKeyword = '';

        foreach ($tokens as $i => $tok) {
            switch ($tok['type']) {
                case 'word':
                    $upper = strtoupper($tok['text']);
                    if ($upper === 'GROUP' || $upper === 'ORDER') {
                        $pendingKeyword = $upper;
                        break;
                    }
                    if ($upper === 'BY') {
                        $clause = $pendingKeyword === 'ORDER' ? 'order_by'
                            : ($pendingKeyword === 'GROUP' ? 'group_by' : $clause);
                        $pendingKeyword = '';
                        break;
                    }
                    $pendingKeyword = '';
                    $clause = $this->clauseFor($upper, $clause);
                    break;

                case 'punct':
                    if ($tok['text'] === '(') {
                        $prev = $this->prevSignificant($tokens, $i);
                        $ctx = 'generic';
                        if ($prev !== null && strtoupper($prev['text']) === 'IN') {
                            $ctx = 'in';
                        } elseif ($prev !== null && strtoupper($prev['text']) === 'VALUES') {
                            $ctx = 'values';
                        }
                        $parenStack[] = $ctx;
                    } elseif ($tok['text'] === ')' && $parenStack) {
                        array_pop($parenStack);
                    }
                    break;

                case 'marker':
                    $idx = (int) $tok['index'];
                    $contexts[$idx] = $this->classifyBareMarker($tokens, $i, $clause, $parenStack);
                    break;

                case 'str_marker':
                    $idx = (int) $tok['index'];
                    $hint = $this->prevWordIs($tokens, $i, ['LIKE']) ? 'like' : null;
                    $contexts[$idx] = new SegmentContext(
                        SegmentContext::STRING_LITERAL,
                        $hint,
                        $tok['prefix'],
                        $tok['suffix'],
                        'dynamic value embedded inside a quoted string literal',
                    );
                    break;

                case 'qid_marker':
                    $contexts[(int) $tok['index']] = new SegmentContext(
                        SegmentContext::IDENTIFIER,
                        null,
                        null,
                        null,
                        'dynamic identifier inside quoted identifier',
                    );
                    break;

                case 'comment_marker':
                    $contexts[(int) $tok['index']] = new SegmentContext(
                        SegmentContext::UNKNOWN,
                        null,
                        null,
                        null,
                        'dynamic fragment appears inside an SQL comment',
                    );
                    break;
            }
        }

        if (count($contexts) !== $markerCount) {
            throw new StructureException(
                'internal',
                'placeholder count mismatch during structural analysis',
            );
        }
        ksort($contexts);
        return $contexts;
    }

    private function clauseFor(string $word, string $current): string
    {
        return match ($word) {
            'SELECT' => 'select',
            'FROM'   => 'from',
            'WHERE'  => 'where',
            'HAVING' => 'having',
            'LIMIT'  => 'limit',
            'OFFSET' => 'offset',
            'INTO'   => 'into',
            'VALUES' => 'values',
            'UPDATE' => 'update',
            'SET'    => 'set',
            'UNION'  => 'union',
            default  => (in_array($word, ['JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS', 'STRAIGHT_JOIN'], true)
                ? 'join' : $current),
        };
    }

    private function classifyBareMarker(array $tokens, int $i, string $clause, array $parenStack): SegmentContext
    {
        $parenCtx = $parenStack[count($parenStack) - 1] ?? null;

        if ($parenCtx === 'in') {
            return new SegmentContext(SegmentContext::IN_LIST, null, null, null, 'placeholder inside IN(...) list');
        }

        $prev = $this->prevSignificant($tokens, $i);
        $next = $this->nextSignificant($tokens, $i);

        // ORDER BY <col-marker> <direction-marker>: a marker whose immediate
        // predecessor is another marker inside ORDER BY is the sort direction.
        if ($clause === 'order_by' && $prev !== null && $prev['type'] === 'marker') {
            return new SegmentContext(SegmentContext::KEYWORD, 'sort_direction', null, null,
                'dynamic sort direction keyword following a dynamic column');
        }

        // Adjacent placeholders (e.g. __RWM0__.__RWM1__) leave a marker as the
        // immediate neighbour. Walk past other markers to the real structural
        // token so the remaining classification stays correct.
        if ($prev !== null && $prev['type'] === 'marker') {
            $prev = $this->prevStructural($tokens, $i);
        }
        if ($next !== null && $next['type'] === 'marker') {
            $next = $this->nextStructural($tokens, $i);
        }

        if ($prev === null) {
            return new SegmentContext(SegmentContext::UNKNOWN, null, null, null, 'whole statement is dynamic');
        }

        $prevText = strtoupper($prev['text']);

        // Comparison / match operators -> value position
        if (in_array($prevText, ['=', '<', '>', '<=', '>=', '!=', '<>', '<=>'], true)) {
            return new SegmentContext(SegmentContext::VALUE);
        }
        if ($prevText === 'LIKE') {
            return new SegmentContext(SegmentContext::VALUE, 'like');
        }
        if ($prevText === 'IS') {
            return new SegmentContext(SegmentContext::VALUE, null, null, null, 'operand of IS');
        }

        // Table positions
        if (in_array($prevText, ['FROM', 'INTO', 'UPDATE', 'TABLE'], true)
            || $clause === 'join' && in_array($prevText, ['JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS'], true)) {
            return new SegmentContext(SegmentContext::IDENTIFIER, 'table', null, null, 'dynamic table name');
        }

        // ORDER BY / GROUP BY column, or qualified column after a dot
        if ($prevText === 'BY' || $prevText === '.') {
            return new SegmentContext(SegmentContext::IDENTIFIER, 'column', null, null, "dynamic column in {$clause}");
        }
        if ($prevText === 'AS') {
            return new SegmentContext(SegmentContext::IDENTIFIER, 'alias', null, null, 'dynamic alias');
        }

        // UPDATE SET <col> =
        if ($clause === 'set' && $next !== null && $next['type'] === 'punct' && $next['text'] === '=') {
            return new SegmentContext(SegmentContext::IDENTIFIER, 'column', null, null, 'dynamic SET target column');
        }

        // Numeric positions
        if ($prevText === 'LIMIT' || $prevText === 'OFFSET' || $clause === 'limit' || $clause === 'offset') {
            return new SegmentContext(SegmentContext::VALUE, 'numeric', null, null, 'numeric LIMIT/OFFSET position');
        }

        // A marker immediately followed by a dot is the table part of a
        // qualified name (t.col), regardless of the preceding keyword.
        if ($next !== null && $next['type'] === 'punct' && $next['text'] === '.') {
            return new SegmentContext(SegmentContext::IDENTIFIER, 'table', null, null,
                'dynamic table in a qualified table.column reference');
        }

        // Whole condition fragments are the classic un-rewriteable case
        if (in_array($prevText, ['WHERE', 'AND', 'OR', 'HAVING'], true)) {
            return new SegmentContext(SegmentContext::CLAUSE_FRAGMENT, null, null, null,
                'dynamic SQL condition fragment (boolean structure is not a value)');
        }

        if ($prev['type'] === 'punct' && $prev['text'] === ',') {
            if ($parenCtx === 'values') {
                return new SegmentContext(SegmentContext::VALUE, null, null, null, 'value in INSERT tuple');
            }
            if ($clause === 'order_by' || $clause === 'group_by') {
                return new SegmentContext(SegmentContext::IDENTIFIER, 'column', null, null, "dynamic column in {$clause}");
            }
            if ($clause === 'select' && $this->firstKeywordAhead($tokens, $i, ['FROM'])) {
                return new SegmentContext(SegmentContext::IDENTIFIER, 'column', null, null, 'dynamic projection column');
            }
            return new SegmentContext(SegmentContext::UNKNOWN, null, null, null,
                'comma-separated position with ambiguous value/identifier role');
        }

        if ($prev['type'] === 'punct' && $prev['text'] === '(') {
            if ($parenCtx === 'values') {
                return new SegmentContext(SegmentContext::VALUE);
            }
            // function argument or parenthesised expression -> value
            $beforeParen = $this->prevSignificant($tokens, $prev['index'] ?? -1);
            if ($beforeParen !== null && $beforeParen['type'] === 'word') {
                return new SegmentContext(SegmentContext::VALUE, null, null, null,
                    'argument of SQL function ' . $beforeParen['text']);
            }
            return new SegmentContext(SegmentContext::VALUE);
        }

        // ORDER BY col <direction>: direction marker follows the column marker
        if ($clause === 'order_by' && $prev['type'] === 'marker') {
            return new SegmentContext(SegmentContext::KEYWORD, 'sort_direction', null, null,
                'dynamic sort direction keyword following a dynamic column');
        }

        // ORDER BY col <direction>
        if ($clause === 'order_by' && $prev['type'] === 'word') {
            return new SegmentContext(SegmentContext::KEYWORD, 'sort_direction', null, null,
                'dynamic sort direction keyword');
        }

        // SELECT <marker> FROM ...
        if ($clause === 'select' && $this->firstKeywordAhead($tokens, $i, ['FROM'])) {
            return new SegmentContext(SegmentContext::IDENTIFIER, 'column', null, null, 'dynamic projection column');
        }

        return new SegmentContext(SegmentContext::UNKNOWN, null, null, null,
            "cannot classify position after '{$prev['text']}' in clause '{$clause}'");
    }

    private function firstKeywordAhead(array $tokens, int $from, array $words): bool
    {
        $words = array_map('strtoupper', $words);
        for ($j = $from + 1, $n = count($tokens); $j < $n; $j++) {
            if ($tokens[$j]['type'] === 'word' && in_array(strtoupper($tokens[$j]['text']), $words, true)) {
                return true;
            }
        }
        return false;
    }

    private function prevWordIs(array $tokens, int $i, array $words): bool
    {
        $prev = $this->prevSignificant($tokens, $i);
        return $prev !== null && in_array(strtoupper($prev['text']), array_map('strtoupper', $words), true);
    }

    private function prevSignificant(array $tokens, int $i): ?array
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (in_array($tokens[$j]['type'], ['ws', 'comment'], true)) {
                continue;
            }
            return $tokens[$j] + ['index' => $j];
        }
        return null;
    }

    private function nextSignificant(array $tokens, int $i): ?array
    {
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            if (in_array($tokens[$j]['type'], ['ws', 'comment'], true)) {
                continue;
            }
            return $tokens[$j];
        }
        return null;
    }

    /** Nearest token before $i that is neither whitespace, comment nor a marker. */
    private function prevStructural(array $tokens, int $i): ?array
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (in_array($tokens[$j]['type'], ['ws', 'comment', 'marker'], true)) {
                continue;
            }
            return $tokens[$j] + ['index' => $j];
        }
        return null;
    }

    /** Nearest token after $i that is neither whitespace, comment nor a marker. */
    private function nextStructural(array $tokens, int $i): ?array
    {
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            if (in_array($tokens[$j]['type'], ['ws', 'comment', 'marker'], true)) {
                continue;
            }
            return $tokens[$j];
        }
        return null;
    }

    private function assertNoStackedStatements(array $tokens): void
    {
        $semicolonSeen = false;
        foreach ($tokens as $tok) {
            if ($tok['type'] === 'punct' && $tok['text'] === ';') {
                $semicolonSeen = true;
                continue;
            }
            if ($semicolonSeen && !in_array($tok['type'], ['ws', 'comment'], true)) {
                throw new StructureException(
                    'skip-stacked-statements',
                    'statement contains a semicolon followed by further SQL (stacked queries cannot be parameterized safely)',
                );
            }
        }
    }

    /**
     * @return list<array{type:string, text?:string, index?:string, prefix?:string, suffix?:string}>
     */
    private function tokenize(string $sql): array
    {
        $tokens = [];
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];

            if (ctype_space($c)) {
                $j = $i + 1;
                while ($j < $len && ctype_space($sql[$j])) {
                    $j++;
                }
                $tokens[] = ['type' => 'ws', 'text' => substr($sql, $i, $j - $i)];
                $i = $j;
                continue;
            }

            // comments
            if ($c === '-' && substr($sql, $i, 2) === '--') {
                $j = strpos($sql, "\n", $i);
                $j = $j === false ? $len : $j + 1;
                $tokens[] = $this->commentToken(substr($sql, $i, $j - $i));
                $i = $j;
                continue;
            }
            if ($c === '#') {
                $j = strpos($sql, "\n", $i);
                $j = $j === false ? $len : $j + 1;
                $tokens[] = $this->commentToken(substr($sql, $i, $j - $i));
                $i = $j;
                continue;
            }
            if ($c === '/' && substr($sql, $i, 2) === '/*') {
                $j = strpos($sql, '*/', $i + 2);
                $j = $j === false ? $len : $j + 2;
                $tokens[] = $this->commentToken(substr($sql, $i, $j - $i));
                $i = $j;
                continue;
            }

            // quoted identifier (`ident` / "ident") possibly containing a marker
            if ($c === '`' || $c === '"') {
                $quote = $c;
                $j = $i + 1;
                $buf = '';
                while ($j < $len && $sql[$j] !== $quote) {
                    $buf .= $sql[$j];
                    $j++;
                }
                $j++; // closing quote
                if (preg_match_all('/__RWM(\d+)__/', $buf, $m)) {
                    if (count($m[0]) > 1) {
                        throw new StructureException('skip-unsupported-expression',
                            'multiple dynamic fragments inside one quoted identifier');
                    }
                    $tokens[] = ['type' => 'qid_marker', 'index' => $m[1][0]];
                } else {
                    $tokens[] = ['type' => 'qid', 'text' => $buf];
                }
                $i = $j;
                continue;
            }

            // single-quoted string literal, possibly containing a marker
            if ($c === "'") {
                $j = $i + 1;
                $buf = '';
                while ($j < $len) {
                    if ($sql[$j] === "'" && substr($sql, $j, 2) === "''") {
                        $buf .= "''";
                        $j += 2;
                        continue;
                    }
                    if ($sql[$j] === "'") {
                        break;
                    }
                    $buf .= $sql[$j];
                    $j++;
                }
                $j++; // closing quote
                if (preg_match_all('/__RWM(\d+)__/', $buf, $m)) {
                    if (count($m[0]) > 1) {
                        throw new StructureException('skip-unsupported-expression',
                            'multiple dynamic fragments inside one string literal');
                    }
                    $marker = $m[0][0];
                    $at = strpos($buf, $marker);
                    $tokens[] = [
                        'type' => 'str_marker',
                        'index' => $m[1][0],
                        'prefix' => substr($buf, 0, $at),
                        'suffix' => substr($buf, $at + strlen($marker)),
                    ];
                } else {
                    $tokens[] = ['type' => 'string', 'text' => $buf];
                }
                $i = $j;
                continue;
            }

            // words / numbers / bare markers
            if (ctype_alnum($c) || $c === '_' || $c === '$') {
                $j = $i + 1;
                while ($j < $len && (ctype_alnum($sql[$j]) || in_array($sql[$j], ['_', '$'], true))) {
                    $j++;
                }
                $word = substr($sql, $i, $j - $i);
                if (preg_match(self::MARKER_RE, $word, $m)) {
                    $tokens[] = ['type' => 'marker', 'text' => $word, 'index' => $m[1]];
                } else {
                    $tokens[] = ['type' => 'word', 'text' => $word];
                }
                $i = $j;
                continue;
            }

            // multi-char operators
            $two = substr($sql, $i, 2);
            if (in_array($two, ['<=', '>=', '<>', '!=', '=>'], true)) {
                $tokens[] = ['type' => 'punct', 'text' => $two];
                $i += 2;
                continue;
            }

            $tokens[] = ['type' => 'punct', 'text' => $c];
            $i++;
        }
        return $tokens;
    }

    private function commentToken(string $text): array
    {
        if (preg_match('/__RWM(\d+)__/', $text, $m)) {
            return ['type' => 'comment_marker', 'text' => $text, 'index' => $m[1]];
        }
        return ['type' => 'comment', 'text' => $text];
    }
}
