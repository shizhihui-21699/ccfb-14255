# SQL Injection Rewriter

一个用 PHP 编写的**静态自动改写工具**，把老系统里直接拼接用户输入的 SQL
语句改写成参数化（prepared statement）写法，并通过运行时白名单守卫保护
无法参数绑定的表名、列名、排序方向等标识符位置。

核心设计目标是题目要求的两条硬约束：

1. **改写后程序行为与原来完全一致**——所有正常输入的查询结果逐字节相同。
2. **无法安全改写的地方明确标出原因**——工具采用「保守拒绝」策略，宁可
   跳过也不产生可能悄悄改变查询结果的改写。

---

## 1. 它解决什么问题

十几年的老 PHP 系统里通常有上千处这样的代码：

```php
$sql = "SELECT * FROM users WHERE username LIKE '%" . $_GET['name'] . "%'";
$rows = legacy_db_get_all($sql);
```

人工逐个改写不现实，而且难点不在于简单的 `WHERE id = $id`，而在于：

- 语句在**多个函数、多个条件分支里一点点拼出来**；
- 有**动态表名、字段名、排序方向、变化的条件个数**；
- 有些地方**故意拼接合法的 SQL 片段**，改写不当会改变查询结果；
- `sprintf`、`implode`、三元、`??`、`match`、转义函数等各种构造混在一起。

本工具用 AST + 数据流分析 + SQL 结构分析自动处理这些形态，并对每一个 SQL
执行点给出「已改写 / 纯静态 / 保守跳过（含原因码）」的明确结论。

---

## 2. 目录结构

```
sql-rewriter/
├── src/
│   ├── Config.php                  项目相关配置：sink、转义函数、schema 白名单、枚举
│   ├── Issue.php                   每个 SQL 执行点的结论 DTO
│   ├── Analyzer/
│   │   ├── Taint.php               污点格（safe / escaped / tainted）
│   │   ├── TaintTracker.php        流敏感的过程内污点 + 分支合并 + SQL 构建轨迹
│   │   ├── Sanitizers.php          intval 等内置净化函数表
│   │   ├── SqlStructure.php        SQL 分词状态机：判定每个动态片段的语法位置
│   │   ├── SegmentContext.php      值/标识符/关键字/IN 列表/条件片段…
│   │   ├── QuerySegment.php        重构出的字面量/动态/可选片段
│   │   ├── QueryFlattener.php      把拼接表达式还原成有序片段（跨变量/分支/函数）
│   │   ├── SqlBuildState.php       条件追加（if 内 $sql .= ...）的结构化轨迹
│   │   ├── FunctionSummary.php     用户函数摘要
│   │   ├── FunctionSummaries.php   跨函数内联（递归保护）
│   │   ├── RewritePlanner.php      片段 + 结构上下文 → 具体改写决策
│   │   ├── FragmentDecision.php    绑定 / 标识符守卫 / 枚举守卫 / 拒绝
│   │   ├── PlanPart.php            带条件的 SQL 片段
│   │   └── AnalysisEngine.php      编排：扫描 sink、逐作用域分析
│   ├── Rewriter/
│   │   ├── Emitter.php             把决策发射成新的 PHP AST
│   │   └── RewriteService.php      文件级解析→分析→改写→打印
│   ├── Runtime/                    改写后代码依赖的运行时
│   │   ├── SafeDb.php              模板展开 + 类型化参数绑定 + 片段执行
│   │   ├── SqlGuard.php            标识符/枚举白名单、IN 列表占位符
│   │   ├── Binding.php             值/标识符/枚举/IN 列表绑定
│   │   ├── Fragment.php            条件 SQL 片段
│   │   ├── SafeDbCompat.php        与 legacy_db_* 同签名的适配层
│   │   ├── UnsafeIdentifierException.php
│   │   └── legacy_stubs.php        遗留转义函数的全局兼容桩
│   ├── Report/                     JSON / Markdown / HTML 报告
│   └── Cli/Application.php         命令行入口
├── bin/rewrite                     改写/分析 CLI
├── bin/run-tests                   一键全量测试
├── bin/demo                        注入对比演示
├── sample-app/
│   ├── original/                   各种拼接写法的示例老系统 + 建表/种子数据
│   └── rewritten/                  工具生成的改写结果
├── tests/
│   ├── SqlStructureTest.php        SQL 结构分类单元测试（21 项）
│   ├── TaintTrackerTest.php        污点追踪单元测试（6 项）
│   └── harness/                    等价性 + 注入失效端到端测试（59 项）
└── output/                         报告与测试数据库
```

---

## 3. 快速开始

```bash
composer install

# 改写整个目录（输出新代码 + 三种格式报告）
php bin/rewrite run sample-app/original \
    --out sample-app/rewritten \
    --report output/sample-report

# 只分析、出报告，不改代码（CI 审计用）
php bin/rewrite analyze sample-app/original \
    --report output/audit-report
```

退出码：`0` 全部处理完且无跳过；`2` 参数错误；`3` 处理完成但存在需要人工
复核的跳过点（可用于 CI 门禁）。

运行演示与测试：

```bash
php bin/demo          # 同一注入载荷：原始版被注入 vs 改写版失效
bin/run-tests         # 单元测试 + 重新改写 + 等价性/注入 harness
```

---

## 4. 改写原理（为什么能保证行为一致）

### 4.1 污点追踪（流敏感）

`TaintTracker` 在每个函数作用域内按语句顺序维护变量的污点事实，事实是一个
小格：

- `safe`：字面量、常量、数值强转、`intval` 等净化函数；
- `escaped`：经过配置的转义函数（如 `legacy_escape`）；
- `tainted`：来自 `$_GET/$_POST/...` 等超全局或其传播。

分支（if/elseif/else、switch、循环、try）在汇合点按格做**保守合并**：任一分支
污染，则合并结果污染。这样不会因为「某个分支看起来安全」而漏掉注入。

### 4.2 查询重构（QueryFlattener）

把到达 sink 的 SQL 表达式还原成有序的「字面量片段 + 动态片段」序列，能追踪：

- 字符串拼接链、双引号插值；
- 变量赋值历史（`$sql = ...; query($sql)`）、`.=` 追加链；
- `sprintf`（手写格式扫描，支持 `%s/%d/%f/...` 与位置参数）；
- `implode/join`（兼容两种参数顺序）形成的 IN 列表；
- 三元 / `??` / `match`（要求各分支字面量布局兼容）；
- 用户函数摘要 + 内联（如 `build_email_lookup($email)`，含递归保护）。

对于 `if (...) $sql .= ...` 这种表达式树无法表达的**条件追加**，工具维护
`SqlBuildState` 结构化轨迹，把每个条件块记录为 `OptionalStep`，最终发射成带
运行时条件的 SQL 片段，条件为假时该片段不参与，精确还原原始控制流。

### 4.3 SQL 结构分类（SqlStructure）

重构只知道「哪里是动态的」，但不知道动态片段处在什么语法位置。`SqlStructure`
是一个宽松的 SQL 分词状态机，在把动态片段替换成占位符后，逐 token 判定每个
占位符的上下文：

| 上下文 | 处理方式 |
|---|---|
| 值（`= ?`、LIKE、LIMIT、INSERT 元组、函数参数） | 占位符 `?` + 参数绑定 |
| IN(...) 列表 | 运行时按数组长度生成 `?,?,?` + 逐元素绑定 |
| 表名 / 列名 / 别名 / `table.column` | 运行时 **schema 白名单守卫**后内联 |
| 排序方向等关键字 | 运行时 **枚举白名单守卫**后内联 |
| 引号内字符串（`'%...%'`） | 剥离引号、通配符并入绑定值，统一为 `?` |
| 整段布尔/结构片段（`WHERE $cond`） | **拒绝**：布尔结构不是可绑定的值 |
| 分号后的堆叠语句 | **拒绝**：参数化无法覆盖第二条语句 |
| 无法可靠分类的位置 | **拒绝** |

### 4.4 标识符为什么不能用占位符

表名、列名、排序方向是 SQL **语法结构**，不是值，prepared statement 的 `?`
不能绑定它们。工具采用双保险：

1. 静态阶段证明该位置所有可见字面量取值都在 schema 白名单内（识别项目里已有的
   `in_array` / `switch` 白名单写法）；
2. 发射运行时 `SqlGuard::identifier()/enum()` 守卫，对静态看不到的变量值做
   白名单校验，非法值抛 `UnsafeIdentifierException`，而不是静默改变查询。

### 4.5 保守拒绝（soundness）

凡是工具无法证明「对任意输入，改写后语义都相同」的位置，一律不改、保留原代码
并在报告中给出原因码。典型例子：

- `WHERE ` . $condition：整段条件结构被拼接，无法参数化；
- 分支返回不同 SELECT 形状（列都不同）：合并会改变结果集；
- 动态 `sprintf` 格式串：占位符结构本身不可信；
- 堆叠查询、无法分类的语法位置、递归 helper 等。

---

## 5. 原因码对照表

| 原因码 | 含义 | 典型人工处理 |
|---|---|---|
| `skip-opaque-sql-fragment` | 拼接了整段布尔/结构 SQL 片段 | 把片段拆成固定结构 + 值绑定 |
| `skip-identifier-no-whitelist` | 标识符无法证明属于白名单 | 扩展 schema 白名单或加 `in_array` 守卫 |
| `skip-unclassifiable-position` | 动态片段语法位置无法可靠判定 | 人工重写为规整语句 |
| `skip-stacked-statements` | 含分号后的堆叠查询 | 拆成独立语句并分别参数化 |
| `skip-reconstruct-failed` | 分支间 SQL 字面量结构不一致/不可追踪 | 统一各分支的语句形状 |
| `skip-unsupported-expression` | 不支持的表达式形态 | 人工改写 |
| `skip-unsupported-sprintf` | sprintf 格式串非常量或含不支持转换符 | 改用拼接结构或绑定 |
| `skip-dynamic-sink-name` / `skip-dynamic-connection` | 动态函数名/连接 | 固化为静态调用 |
| `skip-recursive-helper` | 递归辅助函数无法安全内联 | 人工展开 |
| `static-no-change` | 纯静态 SQL，无注入面 | 无需处理 |

---

## 6. 验证：注入失效且正常结果不变

`tests/harness` 在两个**独立、同构、同种子**的 SQLite 数据库上分别加载原始版与
改写版（用子进程隔离，避免同名函数冲突），对每个场景断言：

1. **等价性**：正常参数下两版返回结果逐字节相同；
2. **注入失效（值位置）**：经典载荷（`' OR '1'='1`、`UNION SELECT`、
   `; DROP TABLE`、`--` 注释等）被当作惰性标量绑定，不改变 SQL 结构；
3. **注入失效（标识符位置）**：恶意表名/列名/排序方向被运行时白名单拦截，或
   被应用层既有白名单收敛，绝不产生 SQL 结构错误；
4. **保守拒绝**：危险形态确实出现在跳过报告中且原因码正确。

当前结果（`bin/run-tests`）：

- SQL 结构分类单元测试：**21/21 通过**
- 污点追踪单元测试：**6/6 通过**
- 端到端等价性 + 注入 harness：**59/59 通过**
- 示例系统 28 个执行点：**23 个参数化改写，5 个保守拒绝（0 个误改）**

`php bin/demo` 可直观看到同一个 UNION 载荷在原始版成功泄露密码哈希、在改写版
返回 0 行且无任何 schema 泄露。

---

## 7. 接入真实老系统

1. 在 `Config` 中登记你们的 SQL 执行函数（sink）、转义函数、数据库 schema
   （用于标识符白名单）和关键字枚举；
2. 先跑 `analyze` 出报告，按原因码分批人工处理跳过点；
3. 改写结果依赖 `src/Runtime`，通过 Composer autoload 引入；`SafeDbCompat`
   保持了 `legacy_db_*` 的同名同签名，调用方除 sink 行外无需改动；
4. 用 CI 门禁（退出码 3）阻止新的拼接 SQL 合入，并持续收敛跳过清单。

## 8. 已知边界

- 分析是**过程内 + 函数摘要**，不做跨对象/跨文件的复杂别名分析；动态方法调用、
  可变变量、`extract()`、字符串变量函数等会被保守跳过。
- 标识符白名单依赖配置的 schema；schema 漂移时需同步更新配置。
- 运行时守卫对非法标识符采取「快速失败抛异常」策略，这是有意为之——静默降级
  会改变查询语义。
