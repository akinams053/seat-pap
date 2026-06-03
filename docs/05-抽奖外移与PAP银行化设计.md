# 05 · 抽奖外移与 PAP 银行化设计

> 本文档是 `feat/pap-bank-externalize` 分支的设计基线。
> 目标：把抽奖功能从 SeAT 插件**外移**到独立服务器，SeAT 退成"PAP 中央账本(bank)"，只保留**余额查询 / 实时扣减 / 退款**三个原子接口；抽奖、（未来恢复的）商店都成为调用这套接口的**外部商户**。
> 现状基线见 [`01-项目说明.md`](01-项目说明.md) §5/§6 与 [`02-整体计划.md`](02-整体计划.md)；本文档描述**新架构**。
>
> **实施状态（2026-06-01）：阶段 1（银行 API + 口径切换）+ 阶段 2（抽奖退役 + 消费审查页）代码已完成并提交，待测试服验证；阶段 3（外部联调）待测试服。进度详见 [`04-交接说明.md`](04-交接说明.md) §1.0。**

---

## 1. 背景与动机

- **真实驱动**：SeAT 服务器不堪重负，需要把"额外功能"搬到其他服务器为 SeAT 减负。
- **附带收益**：抽奖可独立迭代、做更花哨的 UI，不再受插件发版节奏牵制；插件回归"纯 PAP / 统计"核心。
- **范围边界**：
  - 本分支**只交付 SeAT 插件侧**：银行 API + 抽奖退役 + 历史数据口径迁移。
  - 外部抽奖服务（另一台服务器的独立应用）**不在本仓库**，本文档只定义它与 SeAT 的契约。
  - **商店**已确认下线、本体不迁移、不回填；但 debit/refund 接口要做成**商户无关**，给商店保留"原样插回"的恢复路径。

---

## 2. 已锁定的核心决策

| 决策 | 选择 | 理由 |
|------|------|------|
| 账本模型 | **统一为中央账本** | SeAT 是唯一余额权威，彻底消除跨商户双花 |
| 扣费时机 | **每次购买实时扣** | 外部下单→立刻调 debit→SeAT 行锁原子扣→成功才确认；无赊账窗口 |
| 消费在 schema 的落点 | **`source` 列方案** | 把"消费"从"抽奖专属"泛化为"任意商户来源"，商店恢复零成本 |
| 抽奖历史 | **退役代码、三表留档、历史消费打标迁移** | 不破坏既有 `paps.value` 与三口径口径 |
| 三口径消费分类 | **`calendar_operations.is_consumption` 布尔列** | 最小修改，三口径 SQL 直接 `WHERE is_consumption` 判定，替掉 `LEFT JOIN lotteries` |
| 写接口加固 | **独立写 token** | 读/写分离，写 token 泄露不影响只读查询、可单独轮换；暂不加 IP 白名单/限流 |
| 对账与幂等 | **复用 `pap_adjustments` 当账本，不另建表** | `pap_adjustments` 已是逐笔流水；加 `source`+`external_ref`+`ref_group` 即覆盖分类/幂等/ledger/按场次审查 |
| 抽奖/商店建模 | **均非「行动」，每商户一个常驻账本锚 operation** | 「行动」概念只留给真实出勤；外部消费是账本扣减，逐场明细进 `pap_adjustments` |
| 抽奖审查分工 | **玩法审查在外部服务；资金审查在 SeAT 新增「外部消费审查」页** | SeAT 永不持有节点/中奖数据；只保留按场次的资金流水对账（见 §9.3） |

---

## 3. 现状基线（外移要改的就是这些）

### 3.1 PAP 值的单一来源
`paps.value` = `operation tag max(quantifier)` + `Σ PapAdjustment`，由 `Pap::recomputeValueFor()` 回写（`src/Models/Pap.php:165`）。所有统计 / API 直接读 `paps.value`，不单独 sum 调整表。

### 3.2 "消费"目前的定义
三口径（出勤/消费/可用）靠 `LEFT JOIN kassie_calendar_lotteries l` 上 `l.id IS NULL`（出勤）/ `IS NOT NULL`（消费）判定：
- `src/Http/Controllers/ApiController.php:123-130`
- `src/Http/Controllers/CorporationController.php:36-38`

即**消费 = 关联了内部 lottery 的 operation 上的负数调整**。抽奖外移后没有 lottery 行，此判定失效 → 必须换成 `source` 判定。

### 3.3 schema 现状
- `kassie_calendar_pap_adjustments`：`operation_id`(int,NOT NULL)、`character_id`、`value decimal(8,2)` 带符号、`reason(255)`、`created_by_character_id`、`created_at`。**无 source、无幂等键**。
- `kassie_calendar_paps`：主键 `(operation_id, character_id)`，`ship_type_id` NOT NULL。**整库以 operation 为中心** → 外部消费需要一个"落脚 operation"。
- `kassie_calendar_lotteries / _lottery_prizes / _lottery_nodes`：抽奖三表，外移后退为归档。
- API token：单个 `setting('kassie.calendar.api_token')`，`Str::random(48)`（`SettingController.php:95`）。
- JWT 跳转模板：`SettingController::shopRedirect()`（`:146`），抽奖跳转照此实现。

---

## 4. 目标架构总览

```
                       ┌──────────────────────────────────────┐
   浏览器 ──点"抽奖"──> │ SeAT (seat-pap)  =  PAP 中央账本        │
                       │  · 余额查询  GET  /paps                │
   302 + JWT(角色id+   │  · 实时扣减  POST /debit   (幂等/行锁)  │
   余额快照) ────┐     │  · 退款      POST /refund  (幂等/行锁)  │
                │     └───────▲───────────────────▲────────────┘
                ▼             │ Bearer Token       │ Bearer Token
        ┌───────────────┐    │                    │
        │ 外部抽奖服务   │────┘ 下单即调 debit      │ (商店恢复后同样接入)
        │ (另一台服务器) │      退款调 refund        │
        │ 自己跑 UI/轮询 │                          │
        └───────────────┘                          │
```

**职责划分**：
- SeAT：记账 PAP、签发 JWT、提供 `balance/debit/refund` 三接口；是唯一余额权威。
- 外部抽奖服务：全部抽奖 UI、节点状态、轮询、开奖逻辑；**把 SeAT 当"只在交易时调用的银行"**。
- 浏览器：点击与跳转。

---

## 5. ⚠️ 减负成立的硬前提

实时扣意味着**每笔购买仍写 SeAT**（行锁+写调整+recompute）。真正省下的是当前内部抽奖的**大头负载**：

- `lottery.snapshot` 5 秒轮询（`LotteryController.php:589`）——每个围观者每 5 秒一组 COUNT/MAX，N 人 = N×5q/5s。
- `lottery.show` 整页渲染、节点状态、开奖计算。

外移后这些**全部离开 SeAT**。但有一条铁律：

> **外部服务绝不能为了显示余额去轮询 SeAT 的 `GET /paps`。** `debit`/`refund` 的响应**必须直接返回扣完后的最新余额**，外部本地缓存、靠返回值刷新。否则只是把 5 秒轮询从 snapshot 换到 balance 接口，负载原地没动——减负失败。

---

## 6. API 契约（新增写接口）

读接口走现有 `middleware: ['api', 'calendar.api.token']`，鉴权 `Authorization: Bearer <READ_TOKEN>`。
**写接口（debit/refund）走独立的写 token**（`calendar.api.write_token` 中间件，`Bearer <WRITE_TOKEN>`，见 §11）。

### 6.1 余额查询（已存在，保留）
`GET /api/calendar/paps/{character_id}` / `GET /api/calendar/paps?characters=...`
返回主角色聚合后的 `total_pap`（`max(0, …)` 兜底）。详见 `01-项目说明.md` §6.4。

### 6.2 实时扣减 debit（新增）

```
POST /api/calendar/paps/debit
{
  "character_id": 2118151113,        // 主角色或任意 alt，服务端聚合到主角色
  "amount": 10.00,                   // 正数，要扣的 PAP
  "merchant": "lottery",             // 商户标识：lottery / shop / ...
  "idempotency_key": "lt-3-buy-8842",// 外部全局唯一，重试去重 → external_ref
  "ref_group": "lottery:3",          // 活动分组键，按场次审查用 → ref_group
  "reason": "第3期超网·购买节点 #03, #07"  // 人读明细 → pap_adjustments.reason
}
```
> 若将来需结构化 payload（如单独检索节点号），再按 §7.4 加 `metadata` json 列；现阶段 `ref_group` + `reason` 已够审查页用。

服务端逻辑（单事务）：
1. 解析 `character_id` → SeAT user → `associatedCharacterIds()`。
2. **幂等**：`idempotency_key` 已存在 → 直接返回上次结果（不重复扣）。
3. **行锁/advisory lock by user_id**（见 §10）串行化该用户全部并发扣减。
4. 重算可用余额 `SUM(paps.value) where join_time >= 起始日`（不 floor）。
5. `amount > 余额` → `409 余额不足`（含当前余额，外部据此提示）。
6. 写负数 `PapAdjustment`（`source=merchant`，`external_ref=idempotency_key`，`ref_group` 取传入值，挂到该商户的 standing operation，见 §7）→ `recomputeValueFor`。
7. 返回：

```json
{ "status": "success", "balance_after": 9.00, "adjustment_id": 12345, "idempotency_key": "lt-3-buy-8842" }
```

错误码：200 成功 / 400 参数错 / 401 token / 404 角色未找到 / 409 余额不足 / 422 amount 非法 / 503 未配置 token。

### 6.3 退款 refund（新增）

```
POST /api/calendar/paps/refund
{ "character_id":…, "amount":10.00, "merchant":"lottery",
  "idempotency_key":"lt-3-refund-8842", "ref_group":"lottery:3", "reason":"第3期超网·取消退款" }
```

写**正数** `PapAdjustment`，`ref_group` 与原扣费同值（退款归入同一场次），其余同 debit；幂等键独立。退款不要求"原扣费存在"——SeAT 只认金额与商户来源（外部服务负责不超退）。

### 6.4 设计要点
- **金额由 SeAT 校验，不信 JWT 里的余额快照**（快照仅供外部即时显示）。
- 所有写接口**幂等**：同 `idempotency_key` 重复调用结果一致、只扣一次。
- 响应**总是回带最新余额**，杜绝外部轮询（§5 铁律）。

---

## 7. Schema 改动

### 7.1 给 `pap_adjustments` 加来源与幂等
```php
Schema::table('kassie_calendar_pap_adjustments', function (Blueprint $t) {
    // 来源分类：attendance_audit(FC奖惩) / lottery / shop / refund …
    $t->string('source', 32)->default('attendance_audit')->after('value');
    // 外部交易幂等键，外部来源唯一；内部审查可空
    $t->string('external_ref', 191)->nullable()->after('source');
    // 活动分组键（如 "lottery:3"）：同一场抽奖的多笔扣费/退款共享，供「外部消费审查」按场次聚合
    $t->string('ref_group', 64)->nullable()->after('external_ref');
    $t->unique(['source', 'external_ref']);   // 幂等去重（NULL 不参与唯一）
    $t->index('source');
    $t->index(['source', 'ref_group']);       // 按场次聚合
});
```
> `value` 已由 `widen_pap_value_precision`（2026_05_29_100000）加宽到 `decimal(8,2)`（±999,999.99），无需再动。

### 7.2 消费分类标记 `is_consumption`（已定）
```php
Schema::table('calendar_operations', function (Blueprint $t) {
    // 1 = 该 operation 的 paps 计为「消费」(取负)，0 = 计为「出勤」
    $t->boolean('is_consumption')->default(false)->after('is_cancelled');
});
```
三口径 SQL 从 `LEFT JOIN kassie_calendar_lotteries` 改为 `JOIN calendar_operations o`，用 `o.is_consumption` 判定（出勤 = `is_consumption=0` 的 `value`；消费 = `is_consumption=1` 的 `-value`）。

> 备选（未采纳）：独立 `consumption_operations(operation_id, merchant)` 来源表——更解耦但多一层 join 与概念，`is_consumption` 列改动更小，故取列方案。

### 7.3 商户 standing operation
外部消费没有真实 operation，但 schema 以 operation 为中心。方案：**每个商户一个常驻占位 operation**（如 `【外部消费】抽奖` / `【外部消费】商店`），`importance=0`、`is_consumption=1`、绑定 `quantifier=0` 的保留 tag（基础 PAP=0，沿用抽奖保留 tag 思路 `Lottery::reservedTag()`）。

- 该商户所有外部消费的 `Pap` 行落在 `(standing_op_id, character_id)`，每用户一行；`PapAdjustment` 持续累加，`recomputeValueFor` 汇总。
- 因 standing op `is_consumption=1`，其 paps 自动被三口径归为消费，无需再特判商户。
- **它是账本锚，不是「行动」**：外部抽奖/商店都不再是 operation。每用户每商户只此一行 `Pap`（聚合余额用），**逐场/逐单明细全在 `pap_adjustments`**（`source` + `ref_group` + `reason`），由「外部消费审查」页按 `ref_group` 还原场次（见 §9.3）。

> ⚠️ **实现已发现 P0 bug（2026-06-03 测试服 MariaDB 实测）**：`Operation::standingFor()`（`src/Models/Operation.php:270`）建这个 standing 锚时把 `user_id=0`、`fc_character_id=0` 当"系统占位"，但 `calendar_operations.user_id` 有外键 → `users.id`，库里无 id=0 用户 → **debit/refund 必 `500`、完全不可用**。本地 `php -l` 查不出，只有真实数据库（有外键）才暴露。**修复**：`standingFor` 改用调用者真实 `$user->id`，并确认 `fc_character_id=0`(:272) 是否同样撞 characters 外键。详见 [`04-交接说明.md`](04-交接说明.md) §1.0、[`06-API使用说明.md`](06-API使用说明.md) §9。

> 备选（未采纳）：每笔外部消费合成临时 operation——会往 operation 列表塞大量机器生成行，越积越脏，故不取。
> 备选（未采纳）：每场抽奖经 API 注册成一个 operation——即便如此 SeAT 仍拿不到节点/中奖数据（在外部），救不回玩法审查，却重新耦合每场抽奖，故不取。

### 7.4 账本与幂等：复用 `pap_adjustments`，不另建表（已定）
`pap_adjustments` 现有结构（`id / operation_id / character_id / value decimal(8,2) 带符号 / reason(255) / created_by_character_id / created_at`）加上 §7.1 的 `source` + `external_ref` 两列后，**本身就是一张逐笔账本**，一并覆盖分类、幂等、ledger 三用途，因此**不需要单独的 external_txns 表**：

| 需求 | 用 `pap_adjustments` 怎么满足 |
|------|------|
| 幂等去重 | `unique(source, external_ref)`，命中即返回旧结果 |
| 逐笔 ledger 报表 | `WHERE source='lottery' ORDER BY created_at`，每行即一笔流水 |
| 退款不超扣 | `SUM(value) WHERE source='lottery' AND character_id=…` 服务端二次校验 |
| 商户归属 | `source` 列 |
| 人读明细 | `reason`（现抽奖已把节点号写进 reason，沿用） |
| 交易类型 | 由 `value` 符号区分（负=扣费，正=退款/奖励） |

> 若将来确需**结构化 payload 查询**（如按节点号检索），再给 `pap_adjustments` 加一个 `nullable json metadata` 列即可，属增量、非现在必需。`created_by_character_id` 对外部交易填该用户主角色或 0。

---

## 8. 数据迁移

1. **历史调整打标**：现有 `pap_adjustments` 全部回填 `source='attendance_audit'`（默认值已覆盖），但**抽奖产生的扣费/退款要识别出来打 `source='lottery'`**——通过 `operation_id IN (SELECT operation_id FROM kassie_calendar_lotteries)` 批量 update，保证消费口径不断。
2. **历史抽奖消费的归类**：现有抽奖 `Pap` 行挂在各 lottery 的 operation 上。三口径切换到 `is_consumption` 判定后，必须把旧 lottery operation 一并置位，否则会被误算回出勤：
   ```sql
   UPDATE calendar_operations SET is_consumption = 1
    WHERE id IN (SELECT operation_id FROM kassie_calendar_lotteries);
   ```
3. **三表归档**：`lotteries/_prizes/_nodes` 保留只读，不再写入；相关路由/控制器/视图退役（§9）。
4. **前向安全**：所有迁移可重复执行、不破坏旧数据（沿用本项目惯例）。

---

## 9. 抽奖退役与替代入口（插件侧）

### 9.1 退役清单（删除/收缩）
- 路由：`src/Http/routes.php` 的 `lotteries` 整组（`:184-232`）。
- 控制器：`LotteryController`（666 行）。
- 模型：`Lottery / LotteryNode / LotteryPrize`（保留 or 移到 `Archive` 命名空间只读，三表不删）。
- 视图：`src/resources/views/lottery/`。
- 语言：`lang/*/lottery.php`（保留 or 清理）。
- 侧边栏抽奖菜单项移除。

### 9.2 新增：抽奖外链跳转入口
`GET /calendar/lottery/redirect`（照 `shopRedirect()`，JWT 带 `sub/main_character_id/name` + 余额快照 + `exp`），302 跳到外部抽奖服务。

### 9.3 新增：外部消费审查页（资金审查）
> 替代旧"把抽奖当行动、在行动审查里看"的能力。**玩法审查（节点/中奖/开奖）归外部服务，SeAT 不持有这些数据**；本页只做**资金审查**。

- 路由 `GET /calendar/audit/consumption`（+ JSON 接口），权限 `calendar.view`（奖惩/补偿 `calendar.create`）。
- 数据源 = `pap_adjustments WHERE source <> 'attendance_audit'`，**按 `ref_group` 聚合成"场次/订单"**：每组显示商户、活动标签、参与人、扣费/退款逐笔流水、净消费。
  - 历史抽奖消费无 `ref_group`，回退用其原 `operation_id` 分组（旧模型 = 1 抽奖 1 operation）：分组键 `COALESCE(ref_group, CONCAT('op:', operation_id))`。
- FC 纠错：通过 refund / 补偿调整走账本（写 `pap_adjustments` + `recompute`），与现行动审查奖惩同机制。

### 9.4 行动审查列表须排除消费锚
`AuditController`（行动审查）原列出所有有 PAP 的 operation。standing operation 与历史抽奖 operation 现 `is_consumption=1`，**必须从行动审查列表过滤掉**（`WHERE is_consumption = 0`），否则常驻账本锚会混进真实出勤行动里。它们改由 §9.3 的外部消费审查页呈现。

---

## 10. 并发与一致性

- **跨商户/跨抽奖双花**（旧架构隐患，见 02 分析）：实时 debit + **按 user_id 串行化**根除。实现选型：
  - MySQL `GET_LOCK("pap_user_{id}")` advisory lock，或
  - 对该用户全部 `paps` 行 `SELECT … FOR UPDATE` 后再校验+写。
- **幂等**：`external_ref` 唯一约束 + 命中即返回旧结果，防网络重试重复扣。
- **超发策略**：实时扣可直接 `409` 拒绝，无需像旧抽奖那样允许透支兜底；`total_pap` 仍 `max(0,…)` 防显示负数。
- **退款不超退**：SeAT 只认金额，外部服务负责"退不超过已扣"；服务端可用 `SUM(value) WHERE source=… AND character_id=…` 二次校验（账本即 `pap_adjustments`，见 §7.4）。

---

## 11. 鉴权与安全

- 读接口沿用现 `calendar.api.token` 中间件。
- **写接口加固（已定）**：独立的**写 token**（与只读 token 分开存、可单独轮换；写 token 泄露不影响只读查询）。新增 setting `kassie.calendar.api_write_token`，新中间件 `calendar.api.write_token`，挂到 debit/refund 路由。暂不做 IP 白名单 / 限流（后续按需再加）。
- JWT 跳转：HS256、`exp=60s`、`sub=user_id` 做主键（不可用 character_id，会随主角色切换变化——见 01 §6.3）。
- 余额快照写进 JWT 仅供外部即时显示，**不可作为可花额度权威**（权威永远是 debit 时的服务端校验）。

---

## 12. 受影响的统计代码（需随 §7 改造）

把"消费判定"从 `LEFT JOIN lotteries / l.id` 改为"消费标记"后，逐处核对口径不变：
- `ApiController::resolvePap()`（`:123`）— breakdown 三口径。
- `CorporationController` 三个表达式常量（`:36-38`）+ `getMonthlyTrendJson / getGroupedRanking / getTypeDistribution / getConsumedJson`。
  - `getConsumedJson` 现按 `l.id, l.title` 分组（`:181`）→ 改为按 `ref_group`（回退 `operation_id`）分组，标题取活动标签 / 商户译名，"军团消费 PAP 卡"逐场明细才不丢。
  - `getTypeDistribution` 现 `whereNull('l.id')` 排除抽奖（`:114`）→ 改为 `WHERE o.is_consumption = 0` 排除全部消费类 operation。
- `CharacterController`（个人页出勤/消费/可用 breakdown、双线趋势）— 同步改判定。
- `AuditController`（行动审查列表 `index/operationsJson`）— 加 `WHERE is_consumption = 0`，排除消费锚与历史抽奖 operation（见 §9.4）。
- 抽奖余额读取 `LotteryController::availablePap()` 随抽奖退役删除；外部用 `GET /paps` 取。

**验证铁律**：改完后"出勤 − 消费 = SUM(value)"恒等仍成立，主角色聚合 totals 不放大/丢失。

---

## 13. 商店恢复路径

- debit/refund 商户无关（`merchant` 字段区分）→ 商店恢复时**复用同一套接口**，只需商店端改成"下单调 debit"。
- 现有商店 JWT 跳转（`shopRedirect`）、`shop_url` / `api_token` 设置、余额 API **全部保留不删**。
- 不回填商店历史消费（商店下线期间无活跃消费）。

---

## 14. 风险与未决项

**已定（本轮拍板）**：消费分类 = `is_consumption` 列；写接口加固 = 独立写 token；账本 = 复用 `pap_adjustments`，不另建表；精度无需再动。

**风险**：
- **外部服务必须遵守 §5 铁律**（不轮询余额、靠 debit/refund 回带值刷新），否则减负失败——架构成败关键，写进对外协议并在联调压测验证。
- **并发串行化实现**（§10：`GET_LOCK` advisory vs `paps` 行 `FOR UPDATE`）需在写阶段 A 定，但属实现细节、不阻塞设计。
- **standing operation 的 `Pap` 行 `join_time` 取值**：消费要进当月统计，`join_time` 应取交易时刻（沿用现抽奖 `firstOrCreate` 写 `now` 的做法），需确认跨月消费按交易月归集正确。
- 外部抽奖服务本身（UI、开奖、节点、轮询）是另一套工程，不在本分支；本分支只保证 SeAT 侧契约完备、可联调。

---

## 15. 分阶段实施建议

1. **阶段 A — 银行 API**：`source`/`external_ref`/`ref_group` 迁移 + `is_consumption` 列 + standing operation 机制 + `POST debit/refund`（幂等、行锁、返回最新余额）+ 独立写 token 中间件。可独立联调（curl/脚本模拟外部）。
2. **阶段 B — 统计口径切换**：三口径判定从 lotteries-join 改 `is_consumption`；历史数据打标迁移（`source`/`is_consumption` 回填）；逐页核对恒等与主角色聚合。
3. **阶段 C — 抽奖退役 + 审查替代**：删/归档抽奖代码、加抽奖外链跳转入口、建「外部消费审查」页（§9.3）、行动审查列表过滤 `is_consumption`（§9.4）；回归确认插件无残留依赖。
4. **阶段 D — 外部联调**：与外部抽奖服务对接，压测验证"不轮询余额"、幂等重试、并发扣减、退款对账。

> 阶段 A/B 可在 SeAT 侧独立完成并验证；C 依赖外部服务就绪后再切，避免出现"抽奖入口已删、外部还没上线"的空窗。
