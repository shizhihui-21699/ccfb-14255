<?php
declare(strict_types=1);

/**
 * Scenario definitions for the equivalence / injection harness.
 *
 * Each scenario names a repository function (present in both the original
 * and rewritten apps), the arguments to call it with for normal behavior,
 * and a set of injection payloads to prove the rewrite neutralizes.
 *
 * @return array<string, array{
 *   fn:string,
 *   normal_args:array,
 *   payloads?:array<int, array{arg:int, value:mixed, expect:string}>,
 *   kind:string
 * }>
 */
function harness_scenarios(): array
{
    return [
        'U1 numeric id' => [
            'fn' => 'u1_find_by_id',
            'normal_args' => [3],
            'kind' => 'value',
            'payloads' => [
                ['arg' => 0, 'value' => "3 OR 1=1", 'expect' => 'injection-neutralized'],
                ['arg' => 0, 'value' => "3; DROP TABLE users", 'expect' => 'injection-neutralized'],
            ],
        ],
        'U2 LIKE name' => [
            'fn' => 'u2_search_by_name',
            'normal_args' => ['user'],
            'kind' => 'value',
            'payloads' => [
                ['arg' => 0, 'value' => "%' OR '1'='1", 'expect' => 'injection-neutralized'],
                ['arg' => 0, 'value' => "' UNION SELECT password_hash,1,2 FROM users--", 'expect' => 'injection-neutralized'],
            ],
        ],
        'U3 escaped login' => [
            'fn' => 'u3_login_lookup',
            'normal_args' => ['user02'],
            'kind' => 'value',
            'payloads' => [
                ['arg' => 0, 'value' => "admin' --", 'expect' => 'injection-neutralized'],
                ['arg' => 0, 'value' => "x' OR '1'='1", 'expect' => 'injection-neutralized'],
            ],
        ],
        'U4 order column+dir' => [
            'fn' => 'u4_list_sorted',
            'normal_args' => ['email', 'DESC'],
            'kind' => 'identifier',
            'payloads' => [
                ['arg' => 0, 'value' => 'id; DROP TABLE users', 'expect' => 'blocked-by-guard'],
                ['arg' => 0, 'value' => '(SELECT 1)', 'expect' => 'blocked-by-guard'],
                ['arg' => 1, 'value' => 'ASC, (SELECT 1)', 'expect' => 'blocked-by-guard'],
            ],
        ],
        'U5 dynamic filters' => [
            'fn' => 'u5_dynamic_filters',
            'normal_args' => [['role' => 'admin', 'status' => 'active', 'limit' => 5]],
            'kind' => 'conditional',
            'payloads' => [
                ['arg' => 0, 'value' => ['role' => "admin' OR '1'='1"], 'expect' => 'injection-neutralized'],
                ['arg' => 0, 'value' => ['status' => "x' OR 1=1--"], 'expect' => 'injection-neutralized'],
            ],
        ],
        'U6 IN list' => [
            'fn' => 'u6_find_in_ids',
            'normal_args' => [[1, 2, 3]],
            'kind' => 'inlist',
            'payloads' => [
                ['arg' => 0, 'value' => ["1) OR 1=1--"], 'expect' => 'injection-neutralized'],
                ['arg' => 0, 'value' => ["1; DROP TABLE users"], 'expect' => 'injection-neutralized'],
            ],
        ],
        'U7 helper inlined' => [
            'fn' => 'u7_via_helper',
            'normal_args' => ['user03@example.test'],
            'kind' => 'value',
            'payloads' => [
                ['arg' => 0, 'value' => "x' OR '1'='1", 'expect' => 'injection-neutralized'],
            ],
        ],
        'U8 sprintf' => [
            'fn' => 'u8_sprintf_lookup',
            'normal_args' => ['editor', 2],
            'kind' => 'value',
            'payloads' => [
                ['arg' => 0, 'value' => "viewer' OR '1'='1", 'expect' => 'injection-neutralized'],
            ],
        ],
        'U9 ternary value' => [
            'fn' => 'u9_ternary_value',
            'normal_args' => [null],
            'kind' => 'value',
            'payloads' => [
                ['arg' => 0, 'value' => "admin' OR '1'='1", 'expect' => 'injection-neutralized'],
            ],
        ],
        'O1 dynamic table' => [
            'fn' => 'o1_count_in_table',
            'normal_args' => ['orders'],
            'kind' => 'identifier',
            'payloads' => [
                ['arg' => 0, 'value' => 'users; DROP TABLE orders', 'expect' => 'blocked-by-guard'],
            ],
        ],
        'U11 dynamic table guarded' => [
            'fn' => 'u11_dynamic_table',
            'normal_args' => ['users', 1],
            'kind' => 'identifier',
            'payloads' => [
                ['arg' => 0, 'value' => 'users; DROP TABLE users', 'expect' => 'blocked-by-guard'],
                ['arg' => 0, 'value' => 'nonexistent_table', 'expect' => 'blocked-by-guard'],
            ],
        ],
        'U12 dynamic column guarded' => [
            'fn' => 'u12_dynamic_column',
            'normal_args' => ['email', 1],
            'kind' => 'identifier',
            'payloads' => [
                ['arg' => 0, 'value' => 'id; DROP TABLE users', 'expect' => 'blocked-by-guard'],
            ],
        ],
        'O2 insert' => [
            'fn' => 'o2_insert_order',
            'normal_args' => [1, 'Keyboard', 99.5, 'pending'],
            'kind' => 'write',
            'payloads' => [
                ['arg' => 1, 'value' => "Keyboard', 'x'); DROP TABLE orders;--", 'expect' => 'injection-neutralized'],
            ],
        ],
        'O3 update' => [
            'fn' => 'o3_update_status',
            'normal_args' => [1, 'paid'],
            'kind' => 'write',
            'payloads' => [
                ['arg' => 1, 'value' => "paid' WHERE 1=1--", 'expect' => 'injection-neutralized'],
            ],
        ],
        'O4 qualified column' => [
            'fn' => 'o4_qualified_column',
            'normal_args' => ['orders', 'product', 2],
            'kind' => 'identifier',
            'payloads' => [
                ['arg' => 1, 'value' => 'amount; DROP TABLE orders', 'expect' => 'blocked-by-guard'],
            ],
        ],
        'O5 two-level helper' => [
            'fn' => 'o5_two_level_helper',
            'normal_args' => [1, 'pending'],
            'kind' => 'value',
            'payloads' => [
                ['arg' => 1, 'value' => "x' OR '1'='1", 'expect' => 'injection-neutralized'],
            ],
        ],
        'O6 coalesce' => [
            'fn' => 'o6_coalesce',
            'normal_args' => [null],
            'kind' => 'value',
            'payloads' => [
                ['arg' => 0, 'value' => "x' OR '1'='1", 'expect' => 'injection-neutralized'],
            ],
        ],
        'O7 LIKE prefix' => [
            'fn' => 'o7_like_prefix',
            'normal_args' => ['Key'],
            'kind' => 'value',
            'payloads' => [
                ['arg' => 0, 'value' => "%' OR '1'='1", 'expect' => 'injection-neutralized'],
            ],
        ],
        'O9 intval limit' => [
            'fn' => 'o9_intval_limit',
            'normal_args' => ['3'],
            'kind' => 'value',
            'payloads' => [
                ['arg' => 0, 'value' => "3; DROP TABLE orders", 'expect' => 'injection-neutralized'],
            ],
        ],
        'A1 audit insert' => [
            'fn' => 'a1_log_action',
            'normal_args' => [1, 'login', 'ok'],
            'kind' => 'write',
            'payloads' => [
                ['arg' => 2, 'value' => "ok', 'x'); DROP TABLE audit_log;--", 'expect' => 'injection-neutralized'],
            ],
        ],
        'A2 audit select' => [
            'fn' => 'a2_find_user_actions',
            'normal_args' => [1, 'login'],
            'kind' => 'value',
            'payloads' => [
                ['arg' => 1, 'value' => "login' OR '1'='1", 'expect' => 'injection-neutralized'],
            ],
        ],
        'A3 internal fragment' => [
            'fn' => 'a3_internal_fragment',
            'normal_args' => ['detail 01'],
            'kind' => 'value',
            'payloads' => [
                ['arg' => 0, 'value' => "x' OR '1'='1", 'expect' => 'injection-neutralized'],
            ],
        ],
        'A4 group by' => [
            'fn' => 'a4_group_by',
            'normal_args' => ['action', 1],
            'kind' => 'identifier',
            'payloads' => [
                ['arg' => 0, 'value' => 'action; DROP TABLE audit_log', 'expect' => 'blocked-by-guard'],
            ],
        ],
    ];
}

/**
 * Scenarios the tool is expected to REFUSE (and why). The harness asserts
 * these stay un-rewritten so a human must review them.
 *
 * Note: dynamic table/column positions are NOT refusals when the rewriter
 * emits a runtime schema-whitelist guard (see U11/U12 in the safe scenarios
 * behavior). Refusals are reserved for shapes that cannot be made safe at all.
 */
function harness_refusals(): array
{
    return [
        'U10 raw condition'   => ['fn' => 'u10_raw_condition',   'reason' => 'skip-opaque-sql-fragment'],
        'U13 stacked query'   => ['fn' => 'u13_stacked',         'reason' => 'skip-stacked-statements'],
        'U14 dynamic sprintf' => ['fn' => 'u14_dynamic_format',  'reason' => 'skip-sprintf-format'],
        'O8 unknown keyword'  => ['fn' => 'o8_unknown_keyword',  'reason' => 'skip-unclassifiable-position'],
        'A5 shape-changing'   => ['fn' => 'a5_shape_changing_branch', 'reason' => 'skip-reconstruct-failed'],
    ];
}
