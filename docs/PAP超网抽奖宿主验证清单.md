# PAP 超网抽奖 — 宿主验证清单

本清单用于在 **SeAT 宿主环境**（`/var/www/seat`，Laravel 10 / PHP 8.4 / MySQL / Redis）验证抽奖第一版（阶段 1–6）的实际运行效果。

本仓库代码无法在本机直接运行（PHP / MySQL 都在宿主侧），因此所有迁移、事务、PAP 回写逻辑都必须在宿主实测确认。

> 约定：标注「**宿主命令**」的在 SeAT 根目录执行；标注「**SQL**」的在宿主数据库执行（`mysql` 或任意客户端）。下文 SQL 里的 `:lottery_id` / `:op_id` 等请替换为实际值。

## 执行版总览

建议按下面顺序验证，不要跳过账务核对。每完成一项，就在本文件对应阶段旁记录「通过 / 失败 / 未测」与关键 ID。

| 顺序 | 场景 | 最小通过标准 |
| --- | --- | --- |
| 1 | 宿主加载 | 路由、迁移、三张抽奖表、`lottery` enum 均存在 |
| 2 | 创建抽奖 | 详情页可打开，`lottery` / `operation` / tag / nodes / prizes 全部落库 |
| 3 | 购买节点 | 节点被占用，产生负数 `PapAdjustment`，`paps.value` 正确回写 |
| 4 | 售满开奖 | 状态变为 `drawn`，`draw_log.draw_mode = sold_out`，中奖节点合法 |
| 5 | 提前开奖 | `open` 且已有售出节点时可开奖，未售节点写 `voided_at` |
| 6 | 取消退款 | 状态变为 `cancelled`，正数退款 adjustment 生成，净额回到 0 |
| 7 | 行动审查 | lottery 徽标、流水、跳转、整行动清零限制均符合预期 |
| 8 | 回归检查 | 普通 operation PAP 发放、审查、角色/军团统计仍正常 |

### 关键变量记录表

执行时建议先把这些值记下来，后续 SQL 统一替换：

| 变量 | 含义 | 实际值 |
| --- | --- | --- |
| `:lottery_id` | 当前测试抽奖 ID |  |
| `:op_id` | 当前测试抽奖关联 operation ID |  |
| `:lottery_id2` | 取消退款测试抽奖 ID |  |
| `:op_id2` | 取消退款测试 operation ID |  |
| `:lottery_id3` | 提前开奖测试抽奖 ID |  |
| `:op_id3` | 普通 action 清零测试 operation ID |  |
| `:user_id` | 普通成员测试用户 ID |  |
| `:character_id` | 普通成员测试角色 ID |  |

### 单项结果记录模板

```text
阶段：
操作账号 / 角色：
关键 ID：lottery_id=, op_id=, user_id=, character_id=
页面结果：
SQL 核对结果：
是否通过：通过 / 失败 / 未测
异常日志：storage/logs/laravel.log 对应时间片段，如无则写“无”
后续处理：
```

### 失败时优先回收的信息

若页面提示成功但 SQL 不一致，优先保留以下信息再清理测试数据：

```sql
SELECT * FROM kassie_calendar_lotteries WHERE id = :lottery_id;
SELECT * FROM kassie_calendar_lottery_nodes WHERE lottery_id = :lottery_id ORDER BY node_number;
SELECT * FROM kassie_calendar_lottery_prizes WHERE lottery_id = :lottery_id ORDER BY sort_order;
SELECT * FROM kassie_calendar_paps WHERE operation_id = :op_id ORDER BY character_id;
SELECT * FROM kassie_calendar_pap_adjustments WHERE operation_id = :op_id ORDER BY id;
```

## 当前交接进度（2026-05）

- **代码已推到分支**：`docs/pap-hypernet-lottery-plan`
- **测试服已确认部署版本**：`c7655e8`（含「整行动 PAP 清零」「5 秒短轮询」与 PAP 排名除零修复）
- **已实测通过**：
  - 阶段 1 结构类检查（字段精度 / enum / 三张抽奖表 / 索引）
  - 抽奖创建阻塞性 bug 修复：保留 tag 补 `bg_color` / `text_color`
  - 购买前阻塞性 bug 修复：抽奖 PAP 首次创建补 `ship_type_id = 0`
  - 新路由已在宿主注册：`lottery.snapshot`、`operation.audit.zero`
  - 阶段 2：创建后页面/落库完整核对（2026-05-30 用户反馈已验证）
  - 阶段 3：购买节点与负数扣费（2026-05-30 用户反馈已验证）
  - 阶段 3.5：30 角色并发购买模拟（30/30 成功、无重复节点、账务一致、测试数据已清理）
  - 阶段 4：售满开奖（2026-05-30 用户反馈已验证）
  - 阶段 4.4：提前开奖（`draw_mode = early`、未售节点 `voided_at`）（2026-05-30 用户反馈已验证）
  - 阶段 5：取消退款（2026-05-30 用户反馈已验证）
  - 阶段 6 部分验证：旧普通行动执行整行动 PAP 清零后，个人 PAP 页面曾触发 `DivisionByZeroError`，已通过 `c7655e8` 修复并由用户确认恢复。
  - 阶段 6 只读/控制器回归复核（2026-05-30）：审查列表 JSON、抽奖成员明细 JSON、普通清零结果、未终态抽奖清零拒绝、零值排行榜局部视图均已复核通过，详见下方记录。
  - 阶段 6 浏览器 UI 目视确认（2026-05-30 用户确认无误）：抽奖徽标实际渲染、单 PAP 列 `—`、抽奖详情跳转链接等关键 UI 项通过。
- **待继续实测**：
  - 暂无阶段 1–6 阻塞项；如后续新建未退款、未清零的负数 lottery 样本，可顺手复核总额列“消费 PAP xx”文案。

### 本轮只读复核（2026-05-30）

通过 `seat-ssh` 的 `test` target 做了只读检查，未执行迁移、部署、重启、写库或文件修改。

已确认：

- 测试服 Laravel 为 `10.50.2`。
- `akinams053/seat-pap` 只读复核时安装为 `dev-docs/pap-hypernet-lottery-plan`，source commit 为 `871b17cbb56420dfa2a029d08e918780863d9e32`；随后已通过 Composer 更新到 `c7655e89860d882c48453aba85b7396aa0565375`。
- `lotteries` / `lottery.snapshot` / `operation.audit.zero` / PAP API / character 与 corporation PAP 路由均已注册。
- `create_pap_audit_tables`、`widen_pap_value_precision`、`extend_calendar_tags_analytics_enum`、`create_lottery_tables` 均为 `Ran`。
- `kassie_calendar_paps.value` 与 `kassie_calendar_pap_adjustments.value` 均为 `decimal(8,2)`。
- `calendar_tags.analytics` enum 已包含 `lottery`。
- 三张抽奖表存在，且 `lotteries.operation_id` 唯一索引、`lotteries.status` 索引、`lotteries.created_by_character_id` 索引、`lottery_nodes(lottery_id,node_number)` 唯一索引均存在。
- 既有抽奖 `id=3` 为 `drawn`，`draw_log.draw_mode = early`，未售节点已写 `voided_at`，中奖节点落在已售节点中。
- 既有取消抽奖 `operation_id IN (182,183)` 的扣费与退款 adjustment 净额为 `0.00`，对应 `paps.value` 已回到 `0.00`。
- 既有并发测试抽奖 `id=4` 当前为 `open`，`sold_count = 0`，与“测试数据已清理”状态一致。

### 阶段 6 回归：个人 PAP 排名除零（2026-05-30）

在旧普通行动执行「整行动 PAP 清零」后，访问 `/character/2118151113/paps` 曾触发：

```text
DivisionByZeroError: Division by zero
View: common/includes/ranking_table.blade.php
Controller: CharacterController@paps
```

原因：清零后某个排行榜周期内最大 PAP 变为 `0.00`，排名表进度条仍按 `当前 PAP / 最大 PAP * 100` 计算宽度，PHP 8.4 下触发除零。

修复：`c7655e8 fix: 防止 PAP 排名进度条除零`，当最大 PAP `<= 0` 时进度条宽度返回 `0`，正数时才做除法，并限制在 `0–100`。

部署与验证：测试服已通过 `composer update akinams053/seat-pap --no-cache` 更新到 `c7655e8`，并执行 `php artisan view:clear`；用户随后确认个人 PAP 页面恢复。

### 阶段 6 回归：审查友好化与清零限制复核（2026-05-30）

本轮通过 `seat-ssh` 连接 `test`，以只读查询和 Laravel 控制器调用为主，未执行迁移、部署、重启或写库型清零操作。

已确认：

- 测试服仍安装 `akinams053/seat-pap dev-docs/pap-hypernet-lottery-plan`，source commit 为 `c7655e89860d882c48453aba85b7396aa0565375`。
- 以测试用户 `id=120` / main character `2118151113` 登录上下文调用 `AuditController::operationsJson()`，返回 `recordsTotal = 6`，其中抽奖行动 `operation_id IN (182,183,184)` 均返回 `is_lottery = true`、`lottery_id` 非空、`can_audit = true`。
- `AuditController::membersJson(184)` 返回 `is_lottery = true`、`lottery_id = 3`、`analytics = lottery`，成员明细中 `ship_type_id = 0` 时 `ship_name = —`，adjustments 同时包含购买扣费与「审查整行动清零」流水。
- 普通行动 `operation_id = 172` 已存在一次整行动清零结果：3 条「审查整行动清零」反向 adjustment，且该 action 下 3 个成员的 `paps.value` 均为 `0.00`。
- 未终态抽奖 `operation_id = 185` / `lottery_id = 4` 当前 `status = open`。调用 `AuditController::zero(185)` 返回 HTTP `422`，message 为“still open or sold out waiting for draw; zeroing ... is not allowed yet”，验证未终态 lottery action 清零拒绝路径生效。
- 直接渲染 `calendar::common.includes.ranking_table` 的零值排行榜局部视图，`qty = "0.00"` 时成功渲染并输出 `width: 0%`，未再触发除零。

浏览器目视确认：

- 用户已确认 `/calendar/audit` 阶段 6 浏览器 UI 验证无误。
- 覆盖重点包括抽奖徽标、抽奖行动「单 PAP」列 `—`、成员明细 modal 中「查看抽奖详情」按钮等关键 UI 项。
- 后续如新建一个未清零、未退款的负数 lottery 样本，可顺手再确认总额列显示“消费 PAP xx”。

## 测试服连接约定

- Web 入口：`http://ylxh.de`
- 后续如需从当前工作区旁路 SSH 到测试服，优先通过本机项目 `E:\AI\All projects\seat-ssh` 连接。
- **连接前必须先说明将连接的服务器名称与 IP**，并说明本次会执行只读检查还是写入操作；不要在未说明目标服务器/IP 的情况下直接发起连接。
- 不要把实际 IP、账号、私钥内容写入本仓库文档或提交到 git。
- 在宿主上跑 `php artisan` 时，优先使用 `sudo -u www-data php artisan ...`，避免 root 直跑造成权限问题。

---

## 0. 前置：把抽奖分支部署到宿主

1. 让宿主的插件代码切到 / 更新到分支 `docs/pap-hypernet-lottery-plan`（path repository 或重新 `composer require`/`composer update` 对应分支）。
2. 清缓存并发布静态资源：

**宿主命令**
```bash
php artisan route:clear
php artisan config:clear
php artisan view:clear
php artisan vendor:publish --tag=calendar --force   # 若该 tag 不适用，按现有插件发布方式发布 assets
```

3. 确认路由已注册：

**宿主命令**
```bash
php artisan route:list | egrep 'lotteries|audit/zero'
```

应至少能看到以下 9 条核心路由：
```
GET   calendar/lotteries
GET   calendar/lotteries/create
POST  calendar/lotteries
GET   calendar/lotteries/{lottery}
GET   calendar/lotteries/{lottery}/snapshot
POST  calendar/lotteries/{lottery}/purchase
POST  calendar/lotteries/{lottery}/draw
POST  calendar/lotteries/{lottery}/cancel
POST  calendar/operation/{id}/audit/zero
```

---

## 1. 阶段 1：迁移与表结构

### 1.1 跑迁移

**宿主命令**
```bash
php artisan migrate
php artisan migrate:status | egrep 'widen_pap_value|analytics_enum|create_lottery_tables'
```

预期三条都为 `Ran`：

- `2026_05_29_100000_widen_pap_value_precision`
- `2026_05_29_100100_extend_calendar_tags_analytics_enum`
- `2026_05_29_100200_create_lottery_tables`

### 1.2 字段精度已拓宽

**SQL**
```sql
SHOW COLUMNS FROM kassie_calendar_paps LIKE 'value';
SHOW COLUMNS FROM kassie_calendar_pap_adjustments LIKE 'value';
```

两者 `Type` 都应为 `decimal(8,2)`（原先分别是 `decimal(5,2)` / `decimal(6,2)`）。

### 1.3 analytics enum 已含 lottery

**SQL**
```sql
SHOW COLUMNS FROM calendar_tags LIKE 'analytics';
```

`Type` 应包含 `'lottery'`：
```
enum('strategic','pvp','mining','other','untracked','lottery')
```

### 1.4 三张抽奖表已建

**SQL**
```sql
SHOW TABLES LIKE 'kassie_calendar_lotter%';
-- 期望：kassie_calendar_lotteries / _lottery_prizes / _lottery_nodes

SHOW INDEX FROM kassie_calendar_lotteries;      -- operation_id 唯一、status、created_by 索引
SHOW INDEX FROM kassie_calendar_lottery_nodes;  -- (lottery_id,node_number) 唯一
```

> 旧数据安全性：迁移只扩大字段范围、只新增 enum 取值，属前向安全，不应影响既有 PAP 数据。可顺手 `SELECT COUNT(*) FROM kassie_calendar_paps;` 对比迁移前后行数不变。

---

## 2. 阶段 2：创建抽奖

### 2.1 入口

- 侧边栏应出现 **PAP 抽奖**（骰子图标）。
- 进入 `/calendar/lotteries`，点「创建抽奖」。

### 2.2 建一个小抽奖（建议参数）

- 标题：`验证测试`
- 总节点数：`5`
- 单节点 PAP 价格：`1.00`
- 每人最多节点数：`3`
- 奖品：至少 2 个（如「一等奖」「二等奖」），便于验证多奖顺序抽
- 是否允许重复中奖：先选**不允许**

提交后应跳到详情页，状态为 **进行中（open）**。

### 2.3 落库检查

**SQL**
```sql
SELECT id, operation_id, title, node_count, node_price, max_nodes_per_user,
       allow_repeat_winners, status, created_by_character_id
FROM kassie_calendar_lotteries ORDER BY id DESC LIMIT 1;
-- 记下 id 作为 :lottery_id，operation_id 作为 :op_id

SELECT COUNT(*) FROM kassie_calendar_lottery_nodes WHERE lottery_id = :lottery_id;
-- 应等于 node_count（5），且 user_id / purchased_at 全为 NULL

SELECT sort_order, name FROM kassie_calendar_lottery_prizes WHERE lottery_id = :lottery_id ORDER BY sort_order;
```

### 2.4 保留 tag 与绑定

**SQL**
```sql
SELECT id, name, quantifier, analytics FROM calendar_tags WHERE analytics = 'lottery';
-- 应只有一条，quantifier = 0.00

SELECT * FROM calendar_tag_operation WHERE operation_id = :op_id;
-- 该 operation 应绑定上面的 lottery tag
```

---

## 3. 阶段 3：购买节点与扣费

> 用一个**普通成员**账号（已有 ≥ 5 PAP 可花，起始日 2026-01-01 之后）登录，进入该抽奖详情页。

### 3.1 购买前先记基线

**SQL**
```sql
-- 该用户所有关联角色、起始日以来的可用 PAP（与代码口径一致：join_time、不 floor）
SELECT COALESCE(SUM(value),0) AS available_pap
FROM kassie_calendar_paps
WHERE join_time >= '2026-01-01'
  AND character_id IN ( /* 该用户全部 character_id，见下 */ );

-- 取某用户的全部 character_id
SELECT character_id FROM refresh_tokens WHERE user_id = :user_id;
```

### 3.2 买 2 个节点

页面购买区显示「我的可用 PAP / 剩余 / 已购 / 单价」，输入数量 `2` 购买。成功后整页刷新，节点格子里 2 个变绿（我购买的）。

### 3.3 扣费正确性

**SQL**
```sql
-- 应出现一条负数聚合记录，value = -(2 * 1.00) = -2.00
SELECT operation_id, character_id, value, reason, created_by_character_id
FROM kassie_calendar_pap_adjustments
WHERE operation_id = :op_id ORDER BY id DESC LIMIT 1;

-- paps.value 应被回写为 base(0) + 调整合计 = -2.00
SELECT operation_id, character_id, value, join_time
FROM kassie_calendar_paps WHERE operation_id = :op_id;

-- 节点归属已更新
SELECT node_number, user_id, character_id, pap_adjustment_id, purchased_at
FROM kassie_calendar_lottery_nodes
WHERE lottery_id = :lottery_id AND user_id IS NOT NULL;
```

要点：
- `paps.value` 为负，且等于该用户在该 op 下所有 `pap_adjustments.value` 之和（base=0）。
- 购买的节点 `pap_adjustment_id` 指向上面那条负数记录。
- 可用 PAP（3.1 的查询）应比购买前减少 2.00。

### 3.4 校验项快速验证（可选）

- 超过每人上限（已购 2，再买 2，上限 3）→ 应报「超过每人上限」。
- 数量超过剩余 → 应报「剩余节点不足」。
- 余额不足（换个零 PAP 账号）→ 应报「可用 PAP 不足」。

### 3.5 并发购买与 5 秒短轮询（2026-05）

> 如缺少足够的真实测试号，可临时造一批带唯一前缀的测试用户/角色/PAP 数据，跑完后按清单清理。建议**只做购买并发，不开奖**，避免清理复杂化。

已完成过一轮实测场景：

- 抽奖标题：`并发测试`
- 节点数：`31`
- 单价：`1.00`
- 每人上限：`1`
- 临时创建 30 个测试角色，并发各购买 1 个节点

已验证结果：

- 30/30 购买成功
- `sold_count = 30`
- 没有重复节点分配
- `pap_adjustments` 共 30 条、总和 `-30.00`
- `kassie_calendar_paps` 在该抽奖 operation 下共 30 行、总和 `-30.00`
- 清理测试数据后，抽奖恢复为 `open` 且 `sold_count = 0`

可用于复核的核心 SQL：

```sql
-- 当前已售节点数
SELECT COUNT(*)
FROM kassie_calendar_lottery_nodes
WHERE lottery_id = :lottery_id
  AND purchased_at IS NOT NULL
  AND refunded_at IS NULL
  AND voided_at IS NULL;

-- 节点唯一性（并发购买后应等于 sold_count）
SELECT COUNT(DISTINCT node_number)
FROM kassie_calendar_lottery_nodes
WHERE lottery_id = :lottery_id
  AND purchased_at IS NOT NULL
  AND refunded_at IS NULL
  AND voided_at IS NULL;

-- 并发参与者在该抽奖 operation 下的调整汇总
SELECT COUNT(*) AS adjustment_count, COALESCE(SUM(value), 0) AS adjustment_sum
FROM kassie_calendar_pap_adjustments
WHERE operation_id = :op_id
  AND character_id IN (/* 本轮并发测试角色 character_id 列表 */);

-- 并发参与者在该抽奖 operation 下的最终 PAP 汇总
SELECT COUNT(*) AS pap_rows, COALESCE(SUM(value), 0) AS pap_sum
FROM kassie_calendar_paps
WHERE operation_id = :op_id
  AND character_id IN (/* 本轮并发测试角色 character_id 列表 */);
```

---

## 4. 阶段 4：售完后手动开奖

### 4.1 买满触发 sold_out

继续购买直到 5 个节点全部售出（可用 3.2 的成员补够，或换账号买；注意每人上限 3）。售完后：

**SQL**
```sql
SELECT status FROM kassie_calendar_lotteries WHERE id = :lottery_id;
-- 应为 sold_out
```

详情页此时应出现 **开奖** 按钮（需 `calendar.create` 权限的 FC / 管理员账号才可见）。

### 4.2 开奖

点「开奖」确认。成功后整页刷新：中奖节点金色高亮，奖品列表显示中奖者，页面顶部显示开奖人 / 时间。

**SQL**
```sql
SELECT status, drawn_by_character_id, drawn_at FROM kassie_calendar_lotteries WHERE id = :lottery_id;
-- status = drawn，开奖人 / 时间已填

SELECT sort_order, name, winner_node_number, winner_user_id, winner_character_id, drawn_at
FROM kassie_calendar_lottery_prizes WHERE lottery_id = :lottery_id ORDER BY sort_order;

SELECT draw_log FROM kassie_calendar_lotteries WHERE id = :lottery_id;
-- draw_log 为 JSON，含 draw_mode=sold_out、allow_repeat_winners、rounds[]
```

要点（**不允许重复中奖**时）：
- 两个奖品的 `winner_node_number` 应是**不同节点**。
- 两个奖品的 `winner_user_id` 应是**不同用户**（除非中奖人数不足，候选耗尽时该奖 winner 为 NULL）。

> 如改用「允许重复中奖」再测一期：同一用户可中多个奖，但**同一个节点不会中两次**（`draw_log.rounds` 里 `winner_node_number` 互不相同）。

### 4.3 幂等

对已 `drawn` 的抽奖再次 POST `draw`（或刷新后按钮应已消失）应被拒绝（「只有进行中或已售罄的抽奖才能开奖」）。

### 4.4 提前开奖（凑不满人时，2026-05 新增）

> `open` 状态下凑不满人，FC / 管理员可在已有至少 1 个已售节点的前提下手动「提前开奖」。

1. 另建一期（重复 2.2，建议：节点数 `3`、单价 `1`、每人上限 `3`、2 个奖品、勾选「允许重复中奖」）。
2. 用成员账号只买 **2** 个节点（**留 1 个不卖**），保持 `open`。
3. 详情页管理栏应出现黄色 **提前开奖** 按钮（仅 `open` 且 `sold_count > 0` 时显示；一个都没卖时不显示）。
4. 点「提前开奖」，确认框会提示「未售节点将作废」，确认。

**SQL**
```sql
SELECT status, JSON_EXTRACT(draw_log, '$.draw_mode') AS draw_mode
FROM kassie_calendar_lotteries WHERE id = :lottery_id3;
-- status = drawn，draw_mode = "early"

-- 未售出的那个节点应被作废
SELECT node_number, purchased_at, voided_at
FROM kassie_calendar_lottery_nodes WHERE lottery_id = :lottery_id3 ORDER BY node_number;
-- 已购 2 个：purchased_at 非空、voided_at 空；未售 1 个：voided_at 非空

SELECT sort_order, name, winner_node_number, winner_character_id
FROM kassie_calendar_lottery_prizes WHERE lottery_id = :lottery_id3 ORDER BY sort_order;
-- 中奖节点只会落在已售的 2 个节点上，绝不会是被作废的未售节点
```

要点：
- `draw_mode = early`；未售节点 `voided_at` 被填，且**不出现在任何奖品的中奖节点里**。
- 一个节点都没售出时尝试提前开奖应被拒绝（「还没有任何节点售出，无法开奖」）。
- `paps.value` 不因开奖改变（开奖只动节点 / 奖品 / 状态）。

---

## 5. 阶段 5：取消与退款

> 另建一个抽奖（重复 2.2），买几个节点（重复 3.2），保持 `open` 或 `sold_out`，然后取消。

### 5.1 取消并退款

详情页管理栏点「取消并退款」确认。

**SQL**
```sql
SELECT status FROM kassie_calendar_lotteries WHERE id = :lottery_id2;
-- cancelled

-- 每个购买者应新增一条正数退款记录
SELECT character_id, value, reason, created_by_character_id
FROM kassie_calendar_pap_adjustments
WHERE operation_id = :op_id2 ORDER BY id DESC;

-- 退款后该 op 下每个购买者的 paps.value 应回到 0（扣费 + 退款净额为 0）
SELECT character_id, value FROM kassie_calendar_paps WHERE operation_id = :op_id2;

-- 节点应标记已退款
SELECT COUNT(*) FROM kassie_calendar_lottery_nodes
WHERE lottery_id = :lottery_id2 AND refunded_at IS NOT NULL;
```

要点：
- 退款金额（正数）= 该用户已购节点数 × 单价，与扣费口径一致。
- 净额归零：`paps.value` 回到 0。
- 该购买者的可用 PAP 恢复到购买前水平。

### 5.2 幂等

对已 `cancelled` 的抽奖再次 POST `cancel` 应被拒绝（「当前状态不可取消」），且不产生第二条退款。

---

## 6. 阶段 6：行动审查友好化

进入 `/calendar/audit`：

- 抽奖行动这一行标题前应有 **抽奖** 徽标。
- 「单 PAP」列显示 **—**（而非 0）。
- 「总额」列在为负时显示 **消费 PAP xxx**（已开奖 / 进行中且有净消费时）。
- 点开成员明细弹窗，标题栏应有 **查看抽奖详情** 链接（新标签打开对应抽奖页）。
- 弹窗内每个成员的流水（adjustments）应能看到购买扣费（「购买抽奖节点 N 个：#...」）和退款记录。
- 管理员在成员明细弹窗底部应看到 **整行动 PAP 清零** 按钮。

### 6.1 整行动 PAP 清零

> 先用一个**普通 action** 验证成功路径，再用一个**未终态 lottery action** 验证拒绝路径。

1. 选一个普通 action，确保至少有 2 个成员且 `paps.value` 存在非 0 值。
2. 打开成员明细弹窗，点击 **整行动 PAP 清零**。
3. 确认框会提示：通过追加反向奖惩记录冲正，不删除历史记录。

**SQL**
```sql
-- 清零后所有成员的最终 PAP 应归零
SELECT character_id, value
FROM kassie_calendar_paps
WHERE operation_id = :op_id3
ORDER BY character_id;

-- 应能看到每个非 0 成员新增一条反向 adjustment
SELECT character_id, value, reason, created_by_character_id, created_at
FROM kassie_calendar_pap_adjustments
WHERE operation_id = :op_id3
ORDER BY id DESC;
```

要点：
- 每个原本 `paps.value != 0` 的成员都应新增一条反向 adjustment。
- 已是 0 的成员应被跳过，不新增记录。
- 再次执行一次整行动清零，应提示“无需清零”或等价成功提示，不再新增 adjustment。
- 对**未终态 lottery action**（`open` / `sold_out`）执行时，应被拒绝，并提示暂不允许清零。

> 已全额退款的抽奖净额为 0，「总额」会显示 0.00（非「消费 PAP」），属预期。

---

## 7. 回归检查（确认没影响既有 PAP）

- 普通行动的 PAP 发放、行动审查奖惩、角色 / 军团 PAP 统计页应照常工作。
- **已知现象（非缺陷，阶段 7 处理）**：抽奖消费是负数 `paps.value`，会即时进入角色 / 军团 PAP 统计页，使统计把「获得」与「抽奖消费」混在一起。这是计划 §5.3 / 阶段 7 专门要处理的，目前按决策延后。

---

## 8. 回滚（如需）

三条迁移都写了 `down()`，可回滚：

**宿主命令**
```bash
php artisan migrate:rollback --step=3
```

注意：
- enum 回退前会先把 `analytics = 'lottery'` 的 tag 归位为 `untracked`，再收窄 enum。
- 字段精度回退到 `decimal(5,2)` / `decimal(6,2)` 前，请确认没有超出旧范围的值（抽奖累计消费可能 < -999.99），否则应先清理相关数据再回滚。

---

## 附：问题上报要点

若某步结果与预期不符，回报时请附带：

1. 是哪一阶段、哪条 SQL / 哪个页面操作。
2. 期望值 vs 实际值。
3. 相关行的原始数据（`lotteries` / `lottery_nodes` / `paps` / `pap_adjustments` 对应行）。
4. `storage/logs/laravel.log` 中对应时间的报错（如有）。
