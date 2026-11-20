<?php
declare(strict_types=1);

namespace SqlRewriter\Runtime;

/**
 * Thin execution facade used by rewritten code.
 *
 * It owns:
 *  - the global SqlGuard (identifier/enum whitelists)
 *  - final expansion of value / identifier / enum / IN-list markers
 *  - one PDO prepared statement per executed query
 *
 * Rewritten legacy sinks (legacy_db_query() etc.) delegate here, so their
 * return shapes stay identical to the original API.
 *
 * Marker grammar embedded in templates:
 *   __RWV<n>__  value parameter            -> ? + one bound parameter
 *   __RWI<n>__  whitelisted identifier     -> inlined after guard
 *   __RWE<n>__  whitelisted keyword enum   -> inlined after guard
 *   __RWL<n>__  IN(...) list               -> (?, ?, ...) + N parameters
 * where <n> is the index into the bindings array.
 */
final class SafeDb
{
    private static ?SqlGuard $guard = null;
    private static ?\PDO $pdo = null;

    public static function configure(\PDO $pdo, SqlGuard $guard): void
    {
        self::$pdo = $pdo;
        self::$guard = $guard;
    }

    public static function guard(): SqlGuard
    {
        return self::$guard
            ?? throw new \LogicException('SafeDb not configured: call SafeDb::configure() first');
    }

    public static function pdo(): \PDO
    {
        return self::$pdo
            ?? throw new \LogicException('SafeDb not configured: call SafeDb::configure() first');
    }

    /**
     * @param list<Binding> $bindings
     */
    public static function execute(string $template, array $bindings = []): \PDOStatement
    {
        $params = [];
        $sql = self::expand($template, $bindings, $params);
        $stmt = self::pdo()->prepare($sql);
        self::bindAll($stmt, $params);
        $stmt->execute();
        return $stmt;
    }

    /**
     * Execute a statement assembled from conditional fragments.
     *
     * Each Fragment contributes its SQL only when it is active. The
     * bindings array is global; fragments name the binding indices they
     * reference, and parameters are emitted in the final SQL order.
     *
     * @param list<Binding>  $bindings
     * @param list<Fragment> $fragments
     */
    public static function executeFragments(array $bindings, array $fragments): \PDOStatement
    {
        $activeSql = '';
        foreach ($fragments as $fragment) {
            if ($fragment->active !== null && !$fragment->active) {
                continue;
            }
            $activeSql .= $fragment->sql;
        }
        $params = [];
        $sql = self::expand($activeSql, $bindings, $params);
        $stmt = self::pdo()->prepare($sql);
        self::bindAll($stmt, $params);
        $stmt->execute();
        return $stmt;
    }

    /**
     * Bind positional parameters with explicit types where known. This matters
     * for SQLite, whose PDO driver otherwise binds every value as a string and
     * mis-handles numeric comparisons in HAVING/LIMIT clauses.
     *
     * @param list<array{value:mixed, type:?int}> $params
     */
    private static function bindAll(\PDOStatement $stmt, array $params): void
    {
        $position = 1;
        foreach ($params as $param) {
            $type = $param['type'];
            if ($type === null) {
                $type = match (true) {
                    is_int($param['value']) => \PDO::PARAM_INT,
                    is_bool($param['value']) => \PDO::PARAM_BOOL,
                    is_null($param['value']) => \PDO::PARAM_NULL,
                    default => \PDO::PARAM_STR,
                };
            }
            $stmt->bindValue($position, $param['value'], $type);
            $position++;
        }
    }

    /**
     * @param list<Binding> $bindings
     * @param list<mixed>   $params output: flattened positional parameters in order
     */
    public static function expand(string $template, array $bindings, array &$params): string
    {
        $params = [];
        $guard = self::guard();

        return preg_replace_callback(
            '/__RW[VIEL](\d+)__/',
            static function (array $m) use ($bindings, $guard, &$params): string {
                $index = (int) $m[1];
                if (!array_key_exists($index, $bindings)) {
                    throw new \LogicException("missing binding #{$index} for marker {$m[0]}");
                }
                $binding = $bindings[$index];

                return match ($binding->kind) {
                    Binding::VALUE => self::expandValue($binding, $params),
                    Binding::IDENTIFIER => $guard->identifier($binding->value, $binding->hint ?? 'identifier'),
                    Binding::ENUM => $guard->enum($binding->value, $binding->hint ?? ''),
                    Binding::IN_LIST => self::expandInList($binding, $guard, $params),
                    default => throw new \LogicException('unknown binding kind: ' . $binding->kind),
                };
            },
            $template,
        );
    }

    /**
     * @param list<array{value:mixed, type:?int}> $params output
     */
    private static function expandValue(Binding $binding, array &$params): string
    {
        $value = $binding->value;
        if ($binding->literalPrefix !== null || $binding->literalSuffix !== null) {
            $value = ($binding->literalPrefix ?? '') . $value . ($binding->literalSuffix ?? '');
            // affixes make this a string regardless of the original type
            $params[] = ['value' => $value, 'type' => \PDO::PARAM_STR];
        } else {
            $params[] = ['value' => $value, 'type' => $binding->paramType];
        }
        return '?';
    }

    /**
     * @param list<array{value:mixed, type:?int}> $params output
     */
    private static function expandInList(Binding $binding, SqlGuard $guard, array &$params): string
    {
        [$fragment, $flat] = $guard->inList($binding->value);
        foreach ($flat as $v) {
            $params[] = ['value' => $v, 'type' => $binding->paramType];
        }
        return $fragment;
    }
}
