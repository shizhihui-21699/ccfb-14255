<?php
declare(strict_types=1);

namespace SqlRewriter\Report;

/**
 * Human-readable explanations for the stable reason codes, in Chinese
 * for the migration team and English for cross-referencing.
 */
final class ReasonText
{
    private const ZH = [
        'skip-opaque-sql-fragment'       => '整段布尔/结构型 SQL 片段被拼接，其形状不是可绑定的值，无法安全参数化',
        'skip-identifier-no-whitelist'   => '动态表名/列名/关键字无法证明属于白名单，需要显式 in_array 守卫或扩展 schema 白名单',
        'skip-unclassifiable-position'   => '动态片段的语法位置无法可靠判定，为保证行为一致而放弃改写',
        'skip-stacked-statements'        => '语句包含分号后的堆叠查询，参数化无法覆盖第二条语句',
        'skip-reconstruct-failed'        => 'SQL 由无法静态追踪的分支/形状拼成（各分支字面量结构不一致）',
        'skip-unsupported-expression'    => '含不支持的表达式形态（动态 implode 分隔符、空 IN 列表等）',
        'skip-unsupported-sprintf'       => 'sprintf 格式串不是字面量或含不支持的转换符',
        'skip-sprintf-format'            => 'sprintf 格式串不是字面量或含不支持的转换符',
        'skip-dynamic-sink-name'         => '执行 SQL 的函数名本身是动态的，无法确认其语义',
        'skip-dynamic-connection'        => '数据库连接表达式无法静态确定',
        'skip-recursive-helper'          => '递归辅助函数无法安全内联',
        'static-no-change'               => '纯静态 SQL，无注入面，保持原样',
        'rewritten'                      => '已改写为参数化语句',
    ];

    public static function zh(string $code): string
    {
        return self::ZH[$code] ?? $code;
    }
}
