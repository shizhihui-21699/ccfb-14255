# SQL 改写报告

| 指标 | 数量 |
|---|---|
| 识别到的 SQL 执行点 | 28 |
| 成功参数化改写 | 23 |
| 纯静态语句(无需改写) | 0 |
| 保守跳过(需人工) | 5 |

## 跳过原因分布

| 原因码 | 次数 | 含义 |
|---|---|---|
| `skip-reconstruct-failed` | 1 | SQL 由无法静态追踪的分支/形状拼成（各分支字面量结构不一致） |
| `skip-unclassifiable-position` | 1 | 动态片段的语法位置无法可靠判定，为保证行为一致而放弃改写 |
| `skip-opaque-sql-fragment` | 1 | 整段布尔/结构型 SQL 片段被拼接，其形状不是可绑定的值，无法安全参数化 |
| `skip-stacked-statements` | 1 | 语句包含分号后的堆叠查询，参数化无法覆盖第二条语句 |
| `skip-sprintf-format` | 1 | sprintf 格式串不是字面量或含不支持的转换符 |

## audit_repository.php

- ✅ **L15** `legacy_db_execute` — rewritten
  - rewritten to parameterized form (3 bound fragment(s))
  - 原始形态: `INSERT INTO audit_log (user_id, action, detail, created_at) VALUES (?value, '?str', '?str', datetime('now'))`
  - 改写形态: `INSERT INTO audit_log (user_id, action, detail, created_at) VALUES (__RWV0__, __RWV1__, __RWV2__, datetime('now'))`
- ✅ **L23** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (2 bound fragment(s))
  - 原始形态: `SELECT id, action, detail, created_at FROM audit_log WHERE user_id = ?value AND action = '?str' ORDER BY id DESC`
  - 改写形态: `SELECT id, action, detail, created_at FROM audit_log WHERE user_id = __RWV0__ AND action = __RWV1__ ORDER BY id DESC`
- ✅ **L36** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (1 bound fragment(s))
  - 原始形态: `SELECT id, user_id FROM audit_log WHERE action = 'login' AND detail = '?str'`
  - 改写形态: `SELECT id, user_id FROM audit_log WHERE action = 'login' AND detail = __RWV0__`
- ✅ **L50** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (1 bound fragment(s))
  - 原始形态: `SELECT action AS g, COUNT(*) AS n FROM audit_log GROUP BY action HAVING COUNT(*) >= ?value`
  - 改写形态: `SELECT action AS g, COUNT(*) AS n FROM audit_log GROUP BY action HAVING COUNT(*) >= __RWV0__`
- ⛔ **L61** `legacy_db_get_all` — skip-reconstruct-failed
  - SQL variable $sql is branch-merged from different SQL shapes; no single reconstructable definition

## legacy_db.php


## order_repository.php

- ✅ **L17** `legacy_db_get_row` — rewritten
  - rewritten to parameterized form (1 bound fragment(s))
  - 原始形态: `SELECT COUNT(*) AS n FROM ?ident`
  - 改写形态: `SELECT COUNT(*) AS n FROM __RWI0__`
- ✅ **L26** `legacy_db_execute` — rewritten
  - rewritten to parameterized form (4 bound fragment(s))
  - 原始形态: `INSERT INTO orders (user_id, product, amount, status, created_at) VALUES (?value, '?str', ?value, '?str', datetime('now'))`
  - 改写形态: `INSERT INTO orders (user_id, product, amount, status, created_at) VALUES (__RWV0__, __RWV1__, __RWV2__, __RWV3__, datetime('now'))`
- ✅ **L33** `legacy_db_execute` — rewritten
  - rewritten to parameterized form (2 bound fragment(s))
  - 原始形态: `UPDATE orders SET status = '?str' WHERE id = ?value`
  - 改写形态: `UPDATE orders SET status = __RWV0__ WHERE id = __RWV1__`
- ✅ **L41** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (5 bound fragment(s))
  - 原始形态: `SELECT ?ident.?ident AS v FROM ?ident WHERE ?ident.user_id = ?value`
  - 改写形态: `SELECT __RWI0__.__RWI1__ AS v FROM __RWI2__ WHERE __RWI3__.user_id = __RWV4__`
- ✅ **L47** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (2 bound fragment(s))
  - 原始形态: `SELECT id, user_id, product, amount, status FROM orders WHERE user_id = ?value AND status = '?str'`
  - 改写形态: `SELECT id, user_id, product, amount, status FROM orders WHERE user_id = __RWV0__ AND status = __RWV1__`
- ✅ **L56** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (1 bound fragment(s))
  - 原始形态: `SELECT id, product, amount FROM orders WHERE status = '?str'`
  - 改写形态: `SELECT id, product, amount FROM orders WHERE status = __RWV0__`
- ✅ **L64** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (1 bound fragment(s))
  - 原始形态: `SELECT id, product FROM orders WHERE product LIKE '?str%'`
  - 改写形态: `SELECT id, product FROM orders WHERE product LIKE __RWV0__`
- ⛔ **L71** `legacy_db_get_all` — skip-unclassifiable-position
  - syntactic position cannot be classified: cannot classify position after 'amount' in clause 'where'
- ✅ **L78** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (1 bound fragment(s))
  - 原始形态: `SELECT id, product FROM orders ORDER BY id LIMIT ?value`
  - 改写形态: `SELECT id, product FROM orders ORDER BY id LIMIT __RWV0__`

## schema.php


## user_repository.php

- ✅ **L18** `legacy_db_get_row` — rewritten
  - rewritten to parameterized form (1 bound fragment(s))
  - 原始形态: `SELECT id, username, email, role, status FROM users WHERE id = ?value`
  - 改写形态: `SELECT id, username, email, role, status FROM users WHERE id = __RWV0__`
- ✅ **L25** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (1 bound fragment(s))
  - 原始形态: `SELECT id, username, email FROM users WHERE username LIKE '%?str%'`
  - 改写形态: `SELECT id, username, email FROM users WHERE username LIKE __RWV0__`
- ✅ **L33** `legacy_db_get_row` — rewritten
  - rewritten to parameterized form (1 bound fragment(s))
  - 原始形态: `SELECT id, username, password_hash, role FROM users WHERE username = '?str'`
  - 改写形态: `SELECT id, username, password_hash, role FROM users WHERE username = __RWV0__`
- ✅ **L46** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (1 bound fragment(s))
  - 原始形态: `SELECT id, username, email, role, status, created_at FROM users ORDER BY id ?kw`
  - 改写形态: `SELECT id, username, email, role, status, created_at FROM users ORDER BY id __RWE0__`
- ✅ **L62** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (3 bound fragment(s))
  - 原始形态: `SELECT id, username, email, role FROM users WHERE 1 = 1 AND role = '?str' AND status = '?str' LIMIT ?value`
  - 改写形态: `SELECT id, username, email, role FROM users WHERE 1 = 1 AND role = __RWV0__ AND status = __RWV1__ LIMIT __RWV2__`
- ✅ **L69** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (1 bound fragment(s))
  - 原始形态: `SELECT id, username, email FROM users WHERE id IN (?(...))`
  - 改写形态: `SELECT id, username, email FROM users WHERE id IN (__RWL0__)`
- ✅ **L76** `legacy_db_get_row` — rewritten
  - rewritten to parameterized form (1 bound fragment(s))
  - 原始形态: `SELECT id, username, email, role FROM users WHERE email = '?str'`
  - 改写形态: `SELECT id, username, email, role FROM users WHERE email = __RWV0__`
- ✅ **L83** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (2 bound fragment(s))
  - 原始形态: `SELECT id, username, email FROM users WHERE role = '?str' AND id >= ?value`
  - 改写形态: `SELECT id, username, email FROM users WHERE role = __RWV0__ AND id >= __RWV1__`
- ✅ **L91** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (1 bound fragment(s))
  - 原始形态: `SELECT id, username FROM users WHERE role = '?str'`
  - 改写形态: `SELECT id, username FROM users WHERE role = __RWV0__`
- ⛔ **L100** `legacy_db_get_all` — skip-opaque-sql-fragment
  - a whole boolean/structural SQL fragment is concatenated; its shape is not a bindable value
- ✅ **L107** `legacy_db_get_row` — rewritten
  - rewritten to parameterized form (2 bound fragment(s))
  - 原始形态: `SELECT * FROM ?ident WHERE id = ?value`
  - 改写形态: `SELECT * FROM __RWI0__ WHERE id = __RWV1__`
- ✅ **L114** `legacy_db_get_all` — rewritten
  - rewritten to parameterized form (2 bound fragment(s))
  - 原始形态: `SELECT id, ?ident AS picked FROM users WHERE id = ?value`
  - 改写形态: `SELECT id, __RWI0__ AS picked FROM users WHERE id = __RWV1__`
- ⛔ **L121** `legacy_db_get_all` — skip-stacked-statements
  - statement contains a semicolon followed by further SQL (stacked queries cannot be parameterized safely)
- ⛔ **L128** `legacy_db_get_all` — skip-sprintf-format
  - sprintf format string is not a literal

