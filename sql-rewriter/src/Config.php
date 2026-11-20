<?php
declare(strict_types=1);

namespace SqlRewriter;

/**
 * Tool configuration. Everything that makes the tool project-specific
 * (sink names, escaping helpers, identifier whitelist) lives here.
 */
final class Config
{
    /**
     * @param array<string, array{position:int, args:int}> $sinks
     *        callable FQN-like name => ['position' => 0-based arg index holding SQL, 'args' => expected total args]
     * @param array<string, array{position:int, args:int, paramsPosition:int, method?:string}> $pdoSinks
     *        PDO-style prepare/exec called on a connection expression
     * @param array<string, int> $escapeHelpers
     *        callable name => 0-based argument position that gets escaped
     * @param array<string, array{table:string, columns:list<string>}> $schema
     *        identifier whitelist derived from the real database schema
     * @param array<string, list<string>> $enums
     *        keyword position name => allowed literal values (e.g. sort directions)
     * @param array<string, array{glue:string, valueArg:int}> $inListHelpers
     *        known helpers that already build "(?,?,...)" style IN lists
     */
    public function __construct(
        public readonly array $sinks = [],
        public readonly array $pdoSinks = [],
        public readonly array $escapeHelpers = [],
        public readonly array $schema = [],
        public readonly array $enums = [],
        public readonly array $inListHelpers = [],
        public readonly string $runtimeNs = 'SqlRewriter\\Runtime',
    ) {}

    public static function default(): self
    {
        return new self(
            sinks: [
                'legacy_db_query'        => ['position' => 0, 'args' => 1],
                'legacy_db_get_all'      => ['position' => 0, 'args' => 1],
                'legacy_db_get_row'      => ['position' => 0, 'args' => 1],
                'legacy_db_get_column'   => ['position' => 0, 'args' => 1],
                'legacy_db_execute'      => ['position' => 0, 'args' => 2],
                'legacy_log_raw_sql'     => ['position' => 0, 'args' => 1],
            ],
            pdoSinks: [
                // Opt-in only: most legacy systems concentrate SQL in flat
                // wrapper functions. Method-call sinks ($pdo->query/exec) can
                // be enabled here for codebases that call PDO directly.
            ],
            escapeHelpers: [
                'legacy_escape'      => 0,
                'mysql_real_escape'  => 0,
                'mysqli_real_escape' => 1,
            ],
            schema: [
                'users'     => ['table' => 'users',     'columns' => ['id', 'username', 'email', 'password_hash', 'role', 'status', 'created_at']],
                'orders'    => ['table' => 'orders',    'columns' => ['id', 'user_id', 'product', 'amount', 'status', 'created_at', 'n', 'v', 'picked']],
                'audit_log' => ['table' => 'audit_log', 'columns' => ['id', 'user_id', 'action', 'detail', 'created_at']],
            ],
            enums: [
                'sort_direction' => ['ASC', 'DESC'],
                'order_status'   => ['pending', 'paid', 'shipped', 'cancelled', 'refunded'],
                'user_role'      => ['admin', 'editor', 'viewer'],
            ],
            inListHelpers: [
                'legacy_int_list' => ['glue' => ',', 'valueArg' => 0],
            ],
        );
    }

    /** All callable names that execute SQL (flat sinks, not method calls). */
    public function isFlatSink(string $name): bool
    {
        return isset($this->sinks[strtolower($name)]);
    }

    public function sinkSpec(string $name): ?array
    {
        return $this->sinks[strtolower($name)] ?? null;
    }

    public function isPdoMethodSink(string $name): bool
    {
        return isset($this->pdoSinks[strtolower($name)]);
    }

    public function isEscapeHelper(string $name): bool
    {
        return isset($this->escapeHelpers[strtolower($name)]);
    }

    public function escapeArgPosition(string $name): ?int
    {
        return $this->escapeHelpers[strtolower($name)] ?? null;
    }

    public function isKnownIdentifier(string $name): bool
    {
        foreach ($this->schema as $entry) {
            if ($entry['table'] === $name || in_array($name, $entry['columns'], true)) {
                return true;
            }
        }
        return false;
    }

    public function enumFor(string $hint): ?array
    {
        return $this->enums[strtolower($hint)] ?? null;
    }
}
