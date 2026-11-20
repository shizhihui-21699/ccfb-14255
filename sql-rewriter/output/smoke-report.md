# SQL 改写报告

| 指标 | 数量 |
|---|---|
| 识别到的 SQL 执行点 | 4 |
| 成功参数化改写 | 3 |
| 纯静态语句(无需改写) | 0 |
| 保守跳过(需人工) | 1 |

## 跳过原因分布

| 原因码 | 次数 | 含义 |
|---|---|---|
| `skip-opaque-sql-fragment` | 1 | 整段布尔/结构型 SQL 片段被拼接，其形状不是可绑定的值，无法安全参数化 |

## _dbg.php


## _dbg2.php


## _dbg3.php


## _dbg4.php


## smoke.php

- ✅ **L6** `legacy_db_get_row` — rewritten
  - rewritten to parameterized form (1 bound fragment(s))
  - 原始形态: `SELECT id, username, email FROM users WHERE id = ?value`
  - 改写形态: `SELECT id, username, email FROM users WHERE id = __RWV0__`
- ✅ **L11** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (3 bound fragment(s))
  - 原始形态: `SELECT * FROM users WHERE username LIKE '%?str%' ORDER BY ?ident ?kw`
  - 改写形态: `SELECT * FROM users WHERE username LIKE '%__RWV0__%' ORDER BY __RWI1__ __RWE2__`
- ✅ **L16** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (1 bound fragment(s))
  - 原始形态: `SELECT * FROM users WHERE id IN (?(...))`
  - 改写形态: `SELECT * FROM users WHERE id IN (__RWL0__)`
- ⛔ **L21** `legacy_db_get_all` — skip-opaque-sql-fragment
  - a whole boolean/structural SQL fragment is concatenated; its shape is not a bindable value

