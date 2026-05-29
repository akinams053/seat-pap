# PAP 超网抽奖功能计划

> 目标：在 `seat-pap` 插件内实现类似 EVE HyperNet Relay 的 PAP 抽奖玩法。FC 创建“抽奖行动”，成员用 PAP 购买节点，开奖后按节点抽取中奖者；PAP 扣除通过现有行动审查链路落库，便于审计和事后调整。

---

## 1. 已确认的产品决策

### 1.1 扣除模型

采用 **抽奖行动扣除** 模型：

- 每一期抽奖绑定一个特殊 `Operation`。
- 成员购买节点时，系统在该 operation 下创建 / 更新 PAP 记录。
- 购买扣费通过 `kassie_calendar_pap_adjustments` 写入负数调整。
- `Pap::recomputeValueFor()` 回写 `kassie_calendar_paps.value`。
- 行动审查页可以看到该抽奖行动下所有购买者和扣除记录。

不采用独立 PAP Wallet 账本作为第一版主模型。

### 1.2 统计语义

PAP 余额要随抽奖消费减少，但统计页面后续应尽量区分三类数值：

- **当月 PAP / 获得 PAP**：成员通过正常行动获得的 PAP。
- **抽奖消耗 PAP**：成员用于购买抽奖节点的 PAP。
- **可用 PAP**：获得 PAP 减去抽奖消耗后的余额。

第一版实现时，购买节点产生的负数 PAP 会进入 `kassie_calendar_paps.value` 汇总，因此现有 PAP 商店 API 与余额类统计会自然减少。

> 注意：如果某些报表仍希望表达“作战贡献”，后续需要在查询层排除抽奖 operation，或增加 earned / spent / balance 三列。

### 1.3 创建入口

采用 **专用 PAP 抽奖页面**：

- FC 在专用页面创建抽奖。
- 系统自动创建并绑定一个特殊 operation。
- 该 operation 必须能在行动审查中看到。

不建议第一版直接改造现有 operation 创建表单，以避免影响普通行动核心流程。

### 1.4 权限模型

采用 **组织 FC + 管理员** 模型：

- 抽奖创建者 / 绑定 operation 的 FC 可以管理自己创建的抽奖。
- `calendar.setup` 管理员可以管理全部抽奖。
- 普通成员只能查看开放抽奖、购买节点、查看结果。

建议第一版复用现有权限：

| 行为 | 权限建议 |
|---|---|
| 查看抽奖列表 / 详情 | `calendar.view` |
| 创建抽奖 | `calendar.create` |
| 管理自己创建的抽奖 | 抽奖创建者 / operation FC |
| 管理全部抽奖 | `calendar.setup` |
| 开奖 | 抽奖创建者 / operation FC / `calendar.setup`；必须手动点击 |
| 取消 / 退款 | 抽奖创建者 / operation FC / `calendar.setup`；只有取消才退款 |

### 1.5 节点购买方式

采用 **随机分配节点 + 成员选择购买数量**：

- 成员不手动选择具体节点编号。
- 成员输入或选择购买数量。
- 系统从未售节点中随机分配。
- FC 创建抽奖时可设置：
  - 单个节点 PAP 价格；
  - 每人最高节点数量。

**已决策：节点价格 / 总节点数 / 每人上限由 FC 创建时自由填写，只做基本校验**（必须为正数、不超过 `decimal(8,2)` 可表达范围、每人上限不超过总节点数），第一版不设额外硬性上限。

后续如果需要更接近 EVE 超网体验，可以再增加手动选号 UI。

### 1.6 售完、开奖与退款处理

> **决策变更（2026-05）**：应实际运营需要新增**提前开奖**能力——`open` 状态下凑不满人时，FC / 管理员可在「已有至少 1 个已售节点」的前提下手动「提前开奖」。提前开奖只从已售节点抽取，未售节点在开奖时作废（`voided_at`），`draw_log.draw_mode` 记为 `early`；售满后正常开奖仍记 `sold_out`。本节及 §1.7 / §2.4 / §8 / §9 中“必须售完才能开奖、不做未售完开奖”的旧约束，以此变更为准。

不设置自动开奖时间，也不设置到期自动退款。

- 成员购买完全部节点后，抽奖状态从 `open` 变为 `sold_out`。
- 只有进入 `sold_out` 状态后，FC / 管理员页面才显示“开奖”按钮。
- 开奖必须由 FC / 管理员手动点击触发。
- 只有 FC / 管理员取消抽奖行动时才全额退款。
- 退款通过写入正数 `PapAdjustment` 完成。
- 不删除历史购买记录，保持审计链完整。

第一版不做“到期未售完自动退款”，也不做“未售完按已售节点开奖”。

### 1.7 开奖规则

采用 **多奖顺序抽**：

- 一期抽奖可以配置多个奖品。
- 按奖品顺序依次开奖。
- 每个奖品抽出一个中奖节点。
- 同一成员是否可重复中奖为每期可配置项。
- 开奖不自动执行，必须在节点全部售完后由 FC / 管理员手动点击。

重复中奖配置：

| 配置 | 说明 |
|---|---|
| 允许重复中奖 | 成员买得越多，可能中多个奖。 |
| 不允许重复中奖 | 成员中奖一次后，其剩余节点不参与后续奖品抽取。 |

### 1.8 行动审查调整与节点关系

行动审查里的手动奖励 / 扣除 **不影响节点数量或中奖概率**。

原因：

- 行动审查是 PAP 金额审计和修正入口。
- 节点数量、节点编号、中奖概率属于抽奖模块。
- 如果审查手动调整联动节点，容易造成节点编号、价格、原因、退款状态混乱。

第一版规则：

- 节点只能通过抽奖购买、取消退款、后台作废 / 补发节点等抽奖模块动作改变。
- 行动审查只能调整 PAP 金额。
- 审查调整可以用于补偿、额外扣罚、纠错，但不改变既有中奖概率。

### 1.9 可见范围

普通成员可看到 **公开节点归属**：

- 已售节点显示购买者主角色。
- 自己购买的节点可高亮。
- 开奖后中奖节点高亮。

这样透明度最高，也更接近超网体验。

---

## 2. 核心业务流程

### 2.1 FC 创建抽奖

1. FC 进入 `Calendar → PAP 抽奖`。
2. 点击“创建抽奖”。
3. 填写：
   - 抽奖标题；
   - 奖品列表；
   - 总节点数；
   - 单节点 PAP 价格；
   - 每人最多节点数量；
   - 是否允许重复中奖。
4. 不填写开奖时间；开奖只由 FC / 管理员在节点售完后手动触发。
5. 系统创建一条特殊 operation。
6. 系统创建 lottery 主记录与 prize / node 记录。
7. 抽奖进入 `open` 状态。

特殊 operation 要求：

- 标题前缀：`【抽奖】`。
- 必须自动绑定系统保留的抽奖专用 tag。
- 抽奖专用 tag 的 `quantifier` 必须固定为 `0`。
- 抽奖专用 tag 的 `analytics` 建议固定为 `lottery`。
- FC 创建抽奖时不手动选择普通 PAP tag，避免选错导致基础 PAP 不为 0。

### 2.2 抽奖专用 Tag

抽奖行动必须打上专用抽奖 tag。这个 tag 不只是 UI 标记，也是保证扣费和统计语义正确的重要约束。

建议专用 tag：

```text
name = PAP 抽奖 / Lottery
quantifier = 0
analytics = lottery
```

设计规则：

- 创建抽奖时由系统自动创建 / 查找并绑定该 tag。
- FC 不需要、也不应该手动选择普通 PAP tag。
- 该 tag 是系统保留 tag，不允许在普通 tag 管理中删除。
- 不允许把该 tag 的 `quantifier` 改成非 0。
- 行动审查和 operation 列表可以通过该 tag 辅助显示“抽奖行动”。

统计识别建议：

- **主识别**：以 `kassie_calendar_lotteries.operation_id` 判断是否抽奖 operation。
- **辅助识别**：抽奖专用 tag 用于 UI 展示、人工排查和筛选。

这样即使 tag 被异常修改，统计仍可以通过 lottery 表关联保持正确；同时 UI 上也能直观看到该 operation 是抽奖行动。

### 2.3 成员购买节点

1. 成员进入抽奖详情页。
2. 页面显示：
   - 奖品列表；
   - 节点总数；
   - 已售节点数；
   - 节点购买情况，包括已售节点归属主角色；
   - 单节点价格；
   - 每人上限；
   - 自己已购买节点；
   - 当前个人可用 PAP（固定起始日以来，已扣除抽奖消费）。
3. 成员选择购买数量。
4. 系统校验：
   - 抽奖状态为 `open`；
   - 剩余节点足够；
   - 未超过每人上限；
   - 可用 PAP 足够。
5. 系统随机分配未售节点。
6. 系统创建 / 确保该成员在抽奖 operation 下存在 PAP 记录。
7. 系统写入负数 `PapAdjustment`：

```text
value = -(节点数量 * 单节点 PAP 价格)
reason = 购买抽奖节点 N 个：#01, #08, #33
```

8. 系统调用 `Pap::recomputeValueFor()` 回写 `kassie_calendar_paps.value`。
9. 页面刷新节点状态和余额。

### 2.4 售完后手动开奖

1. 所有节点售完后，系统将抽奖状态改为 `sold_out`。
2. 抽奖详情页显示“开奖”按钮。
3. FC / 管理员手动点击“开奖”。
4. 系统按奖品顺序开奖。
5. 每轮从有效节点池中随机抽一个节点。
6. 保存：
   - 中奖奖品；
   - 中奖节点；
   - 中奖用户；
   - 中奖主角色；
   - 开奖随机记录；
   - 开奖人；
   - 开奖时间。
7. 抽奖状态变为 `drawn`。

如果配置为“不允许重复中奖”：

- 某用户中奖后，该用户剩余节点从后续奖品抽取池中排除。

### 2.5 FC 取消与退款

只有 FC / 管理员主动取消抽奖时才退款。

1. FC / 管理员点击“取消并退款”。
2. 系统按购买记录生成退款调整：

```text
value = +(节点数量 * 单节点 PAP 价格)
reason = 抽奖取消，退还节点 #01, #08, #33
```

3. 调用 `Pap::recomputeValueFor()`。
4. 抽奖状态变为 `cancelled`。

第一版不设置抽奖到期时间，因此不存在“到期自动退款”。未售完也不允许开奖，必须售完后才显示开奖按钮。

---

## 3. 数据设计建议

### 3.1 `kassie_calendar_lotteries`

抽奖主表。

| 字段 | 类型建议 | 说明 |
|---|---|---|
| `id` | big increments | 主键 |
| `operation_id` | integer | 绑定的抽奖 operation |
| `title` | string | 抽奖标题 |
| `node_count` | integer | 总节点数 |
| `node_price` | decimal(8,2) | 单节点 PAP 价格 |
| `max_nodes_per_user` | integer nullable | 每人最多节点数 |
| `allow_repeat_winners` | boolean | 是否允许同一用户重复中奖 |
| `status` | string | `draft` / `open` / `sold_out` / `drawn` / `cancelled` |
| `created_by_character_id` | bigint | 创建人主角色 |
| `drawn_by_character_id` | bigint nullable | 开奖人主角色 |
| `drawn_at` | timestamp nullable | 开奖时间 |
| `draw_log` | json nullable | 开奖随机记录 |
| `created_at` | timestamp | 创建时间 |
| `updated_at` | timestamp | 更新时间 |

建议索引：

- `operation_id` unique
- `status`
- `created_by_character_id`

### 3.2 `kassie_calendar_lottery_prizes`

多奖品表。

| 字段 | 类型建议 | 说明 |
|---|---|---|
| `id` | big increments | 主键 |
| `lottery_id` | bigint | 所属抽奖 |
| `sort_order` | integer | 奖品顺序 |
| `name` | string | 奖品名称 |
| `description` | text nullable | 奖品说明 |
| `winner_node_number` | integer nullable | 中奖节点 |
| `winner_user_id` | bigint nullable | 中奖 SeAT 用户 |
| `winner_character_id` | bigint nullable | 中奖主角色 |
| `drawn_at` | timestamp nullable | 该奖品开奖时间 |

建议索引：

- `lottery_id, sort_order`
- `lottery_id, winner_user_id`

### 3.3 `kassie_calendar_lottery_nodes`

节点表。建议创建抽奖时预生成全部节点，购买时更新归属。

| 字段 | 类型建议 | 说明 |
|---|---|---|
| `id` | big increments | 主键 |
| `lottery_id` | bigint | 所属抽奖 |
| `node_number` | integer | 节点编号 |
| `user_id` | bigint nullable | 购买者 SeAT 用户 |
| `character_id` | bigint nullable | 购买时主角色 |
| `pap_adjustment_id` | bigint nullable | 对应扣费调整记录 |
| `purchased_at` | timestamp nullable | 购买时间 |
| `refunded_at` | timestamp nullable | 退款时间 |
| `voided_at` | timestamp nullable | 作废时间，可选 |

建议约束 / 索引：

- unique(`lottery_id`, `node_number`)
- index(`lottery_id`, `user_id`)
- index(`pap_adjustment_id`)

### 3.4 前置数据库改造（已决策）

抽奖功能依赖两处现有 schema 改造，必须在阶段 1 最先完成，否则会导致数据溢出或写入失败。

#### 3.4.1 拓宽 PAP 金额字段精度

现有字段精度太小，存不下抽奖累计消费产生的负数：

| 字段 | 现状 | 改为 | 上限 |
|---|---|---|---|
| `kassie_calendar_paps.value` | `decimal(5,2)` | `decimal(8,2)` | ±999,999.99 |
| `kassie_calendar_pap_adjustments.value` | `decimal(6,2)` | `decimal(8,2)` | ±999,999.99 |

要点：

- 两个字段都必须保持**有符号**，能存负数（抽奖扣费、扣罚）。
- `node_price` 同样用 `decimal(8,2)`，三处精度统一。
- 这是前向安全改造，只扩大范围，不影响既有数据。

> 背景：原 `paps.value` 仅 `decimal(5,2)`（上限 ±999.99）。`recomputeValueFor()` 会把 `base(0) + Σadjustments` 回写进该字段，一旦用户抽奖累计消费超过 999.99 就会溢出报错。

#### 3.4.2 扩展 `analytics` enum

抽奖专用 tag 要用 `analytics = lottery`，但现有 enum 没有该值：

```text
现状：enum('analytics', ['strategic','pvp','mining','other','untracked'])
改为：enum('analytics', ['strategic','pvp','mining','other','untracked','lottery'])
```

要点：

- 加一条 migration 扩展该 enum，新增 `lottery`。
- 抽奖专用 tag 固定 `analytics = lottery`、`quantifier = 0`。
- 好处：行动审查列表 / operation 列表可直接靠该类型识别并标记抽奖行动。

---

## 4. 与现有 PAP / 行动审查的集成

### 4.1 创建抽奖 PAP 记录

购买节点时，如果用户在抽奖 operation 下还没有 PAP 记录，需要创建一条：

```text
operation_id = lottery.operation_id
character_id = 用户当前 main_character_id
join_time = 当前时间
created_at = 当前时间
value = 0，然后由 recompute 回写
```

注意：这里不应通过 ESI fleet members 创建 PAP，因为抽奖行动不是实际舰队。

### 4.2 扣费写入 PapAdjustment

每次购买可以写一条聚合扣费记录：

```text
value = -总价
reason = 购买抽奖节点 N 个：#...
created_by_character_id = 购买者 main_character_id
```

如果管理员后台代购 / 补发节点，`created_by_character_id` 应记录操作管理员，reason 说明目标成员。

### 4.3 退款写入 PapAdjustment

退款不删除原扣费记录，而是追加正数记录：

```text
value = +退款金额
reason = 抽奖取消，退还节点 #...
```

这样审计链完整。

### 4.4 行动审查页语义（已决策：混在一起 + 打标签）

#### 4.4.1 列表与流水的两个层面

要先区分两个不同层面的“一条”，避免混淆：

| 层面 | 一个抽奖行动产生几条 | 出现在哪 |
|---|---|---|
| **审查列表的行** | 就 **1 条**（= 1 个 operation） | 审查列表主页 |
| **某成员的扣费流水**（`PapAdjustment`） | 每次购买 1 条、退款再 1 条 | 该成员的明细弹窗内 |

也就是说：

- 审查列表里，**一个抽奖行动就是一行**，和普通行动完全一样；点进去是该行动下所有购买者（每人作为一个成员）。
- “每次购买新增一条”指的是成员明细弹窗里**那个人的扣费流水**，等同于普通行动中 FC 多次手动奖惩同一个人留下的多条流水，**不是列表里的独立行**。
- `paps` 表以 `(operation_id, character_id)` 为主键，**一个人在一个抽奖里只有一条 pap 记录**；买多次只是不断累加 `PapAdjustment` 并回写这一条的 `value`。

#### 4.4.2 默认行为（几乎零改动即可用）

抽奖 operation 因为有购买产生的 pap 记录，会**自动出现在审查列表**，且：

- **单 PAP**（`MAX(t.quantifier)`）= `0`（抽奖 tag quantifier=0）。
- **总额**（`SUM(p.value)`）= 负数（全员净消费）。
- **成员明细弹窗**（`membersJson`）零改动即可用：每个成员的 `value` 是其净消费（负数），`adjustments` 列表天然展示每条购买扣费（reason 形如“购买抽奖节点 N 个：#01...”）和退款——这本身就是完整审计链。

#### 4.4.3 列表层面的处理：混在一起 + 打标签

抽奖行动仍出现在同一个审查列表中，但需要可视化区分：

- 列表中给抽奖行动加一个“抽奖”徽标（靠 `analytics = lottery` 或 join `lotteries` 表识别）。
- 抽奖行动的“单 PAP”显示为 `—`（而非 `0`），避免和真实 PAP 混淆。
- 总额为负数时显示为“消费 PAP xxx”。
- 从审查明细弹窗提供跳转到抽奖详情页的链接。

> FC 仍可在审查页对抽奖行动做手动奖惩调整，但按 §1.8，这只调整 PAP 金额，**不影响节点数量或中奖概率**。

---

## 5. 可用 PAP 计算

### 5.1 第一版口径

**已决策：固定起始日锁定为 `2026-01-01`**（与现有 PAP 统计口径一致），只统计该日期及之后的 PAP 作为可花余额：

```text
2026-01-01
```

可用 PAP：

```text
固定起始日以来，用户所有关联角色的 kassie_calendar_paps.value 之和
```

该值会自然包含：

- 普通行动发放 PAP；
- 行动审查奖惩；
- 抽奖购买扣除；
- 抽奖取消退款。

由于第一版不设置到期时间，余额变化只来自购买扣除、FC 取消退款、行动审查手动调整，不存在到期自动退款。

### 5.2 主角色聚合

抽奖账户必须以 SeAT `user_id` 为唯一账户键。

展示身份使用开奖 / 购买时的：

- `main_character_id`
- 主角色名

这样可以避免用户通过 alt 分散购买或绕过每人上限。

### 5.3 军团统计与导出调整

抽奖消费进入 PAP 汇总后，军团 PAP 页面需要同步调整展示语义，避免“作战获得”和“抽奖消费”混在一起看不清。

#### 军团 PAP 页面

建议取消现有 **PAP 类型分布（年度）** 图表，用空出来的位置展示军团级汇总卡片 / 图表：

```text
军团总 PAP = 普通行动获得 PAP（排除抽奖 operation）
抽奖消耗 PAP = 抽奖 operation 产生的负数 PAP 消费，展示时转为正数
可用 PAP = 军团总 PAP - 抽奖消耗 PAP
```

统计起始日期固定为：

```text
2026-01-01
```

展示维度：

- 支持按月查看：展示某月军团获得 PAP、抽奖消耗 PAP、可用净值变化。
- 支持按整年查看：展示某年累计获得 PAP、抽奖消耗 PAP、可用净值变化。
- 默认可显示从 `2026-01-01` 至今的累计总览。

实现上应通过 lottery 表关联识别抽奖 operation：

```text
普通行动获得 PAP：排除 lotteries.operation_id 对应的 paps
抽奖消耗 PAP：只统计 lotteries.operation_id 对应的负数 paps / adjustments，并以正数展示
```

#### 军团 PAP 排名导出

军团 PAP 排名导出 Excel / CSV 时，也要包含所选时间范围内的抽奖消耗 PAP。

建议导出列至少包含：

| 列 | 含义 |
|---|---|
| 主角色 | 按 SeAT user main character 聚合后的展示角色 |
| 获得 PAP | 所选时间范围内普通行动获得 PAP |
| 抽奖消耗 PAP | 所选时间范围内抽奖 operation 消耗 PAP，正数展示 |
| 可用 PAP | 获得 PAP - 抽奖消耗 PAP |

导出时间范围应与页面筛选一致：

- 按月导出时，抽奖消耗只统计该月。
- 按年导出时，抽奖消耗只统计该年。
- 如果页面支持自定义范围，导出也使用同一范围。

### 5.4 后续报表建议

后续如果要让统计更清晰，可在角色 / 军团统计页统一增加：

```text
当月获得 PAP = 普通行动 PAP 正向产出
抽奖消耗 PAP = 抽奖 operation 负数消费，展示为正数
可用 PAP = 获得 PAP - 抽奖消耗 PAP
```

需要给 operation 增加类型标识，或通过 lottery 表关联识别抽奖 operation。

---

## 6. 并发、延迟与一致性要求

抽奖购买、开奖、退款都属于强一致性动作。第一版不应依赖前端显示状态判断结果，所有关键判断必须在服务端事务内重新计算。

### 6.1 购买节点的事务边界

购买节点必须放在数据库事务里。

建议流程：

1. 锁定 lottery 主记录。
2. 确认状态仍为 `open`，且未开奖、未取消、未退款。
3. 查询并锁定未售节点。
4. 在锁内重新校验剩余数量、用户上限、余额。
5. 随机选择节点。
6. 更新节点归属。
7. 创建 / 确保抽奖 operation 下的 `Pap` 记录。
8. 写入负数 `PapAdjustment`。
9. 调用 `Pap::recomputeValueFor()`。
10. 提交事务。

关键要求：

- 不能超卖节点。
- 同一个节点只能被购买一次。
- PAP 不足时不能购买。
- 用户购买上限必须在事务内重新检查，不能只信页面显示。
- 购买成功但扣费失败时必须整体回滚。
- 扣费成功但节点更新失败时必须整体回滚。

### 6.2 节点锁与唯一约束

建议创建抽奖时预生成全部节点，并给节点表增加：

```text
unique(lottery_id, node_number)
index(lottery_id, user_id)
```

购买时只更新 `user_id is null` 的节点。实现上可选两种方式：

1. **锁定 lottery 主行后串行购买**：简单可靠，适合第一版；同一期抽奖同一时刻只处理一个购买请求。
2. **锁定候选节点行**：更高并发，但实现更复杂，需要 `lockForUpdate()` 锁定未售节点。

考虑 SeAT 内部插件的使用规模，第一版推荐锁定 lottery 主行，牺牲一点峰值并发，换取实现清晰和数据正确。

### 6.3 幂等与重复提交

需要防止用户双击按钮、浏览器重试、网络超时后重复提交导致重复扣费。

建议购买接口支持 `request_id`：

```text
lottery_id + user_id + request_id 唯一
```

如果同一个 `request_id` 已成功处理，直接返回原购买结果，而不是再次扣费。第一版也可以先通过前端禁用按钮降低重复提交概率，但最终仍建议服务端做幂等保护。

开奖、取消、退款也必须做幂等：

- 只有 `sold_out` 状态可以开奖。
- 已 `drawn` 的抽奖不能再次开奖。
- 已 `cancelled` 且退款完成的抽奖不能再次退款。
- `PapAdjustment` 退款记录应能关联到 lottery / node，避免重复退款。

### 6.4 余额延迟与页面显示

页面展示的“可用 PAP”只能作为提示，不作为最终购买依据。

原因：

- 用户可能同时开多个浏览器窗口购买。
- 另一个抽奖可能同时扣费。
- FC 可能正在行动审查中手动调整 PAP。
- 用户页面停留时间过长，余额已经过期。

因此购买提交时必须在事务内重新计算余额。如果余额不足，返回明确错误，例如：

```text
余额已变化，当前可用 PAP 不足，请刷新后重试。
```

前端应在购买成功或失败后刷新：

- 可用 PAP；
- 已售节点；
- 我的节点；
- 节点归属。

### 6.5 售完后的手动开奖

不在“最后一个节点购买请求”里直接执行开奖逻辑。

固定规则：

1. 最后一个节点购买成功后，只把状态标记为 `sold_out`。
2. 抽奖详情页出现“开奖”按钮。
3. 由 FC / 管理员手动点击开奖。
4. 开奖过程单独加锁 lottery 主行，并再次确认状态仍为 `sold_out`。

这样可以避免购买请求等待过久，也能让 FC 在开奖前最终确认奖品、节点和购买记录。

第一版不做自动开奖，也不设置开奖时间。

### 6.6 取消与退款延迟

第一版不设置到期时间，因此没有到期自动处理。

退款只发生在 FC / 管理员手动取消抽奖时：

- 取消操作必须锁定 lottery 主行。
- 只有 `open` 或 `sold_out` 且尚未开奖的抽奖可以取消。
- 取消后状态变为 `cancelled`。
- 系统按已购买节点生成正数退款调整。

退款必须批量事务化处理，或按用户分批处理并记录进度。节点数量不大时可在一个事务内完成；如果未来节点量很大，应改成队列分批退款。

### 6.7 前端实时性

第一版不需要 WebSocket。

推荐策略：

- 抽奖详情页定时轮询轻量 JSON，例如每 10～30 秒刷新节点售出状态。
- 用户点击购买前后强制刷新一次关键状态。
- 前端节点格子应显示每个节点的状态与购买者主角色，方便成员看到购买情况。
- 如果提交时节点已售完或余额不足，以服务端结果为准。

这样能避免引入实时推送复杂度，也符合当前 Blade + jQuery + DataTables 的技术形态。

---

## 7. 路由与页面建议

### 7.1 页面路由

建议新增：

```text
GET  /calendar/lotteries
GET  /calendar/lotteries/create
POST /calendar/lotteries
GET  /calendar/lotteries/{lottery}
POST /calendar/lotteries/{lottery}/purchase
POST /calendar/lotteries/{lottery}/draw
POST /calendar/lotteries/{lottery}/cancel
```

### 7.2 页面结构

#### 抽奖列表页

展示：

- 标题；
- 状态；
- 奖品数量；
- 节点进度；
- 单节点价格；
- 创建 FC；
- 操作按钮。

#### 抽奖详情页

展示：

- 奖品列表；
- 节点格子；
- 已售节点归属；
- 我的节点；
- 当前个人可用 PAP，并在购买区醒目显示；
- 购买数量输入；
- 开奖结果；
- 管理操作。

节点购买情况展示建议：

- 使用节点格子展示全部节点编号。
- 未售节点显示为空位。
- 已售节点显示购买者主角色名，可选显示头像。
- 当前登录用户购买的节点高亮。
- 节点售完后显示 `sold_out` 状态，并只对 FC / 管理员显示“开奖”按钮。
- 购买区域顶部显示当前个人可用 PAP、单节点价格、最多还能购买的节点数。
- 开奖后中奖节点高亮，并显示对应奖品。

节点颜色建议：

| 状态 | 样式 |
|---|---|
| 未售 | 灰色 |
| 他人已购 | 蓝色 |
| 我已购 | 绿色 |
| 中奖节点 | 金色 |
| 已退款 / 作废 | 灰色斜纹，可选 |

---

## 8. 状态机建议

抽奖状态：

```text
draft -> open -> sold_out -> drawn
              -> cancelled
```

**已决策：第一版不做 `draft` 状态**，FC 填完创建表单后抽奖直接进入 `open`。`draft` 仅作为未来可选扩展保留在状态图中。

状态说明：

| 状态 | 说明 |
|---|---|
| `open` | 可购买节点 |
| `sold_out` | 节点已全部售完，等待 FC / 管理员手动开奖 |
| `drawn` | 已开奖，不可购买/退款 |
| `cancelled` | FC / 管理员手动取消并退款 |

只有 `sold_out` 状态显示开奖按钮。未售完不能开奖，只能继续购买或由 FC / 管理员取消并退款。

---

## 9. 开奖审计记录

`draw_log` 建议保存结构化 JSON，例如：

```json
{
  "draw_mode": "sold_out",
  "allow_repeat_winners": false,
  "rounds": [
    {
      "prize_id": 1,
      "prize_name": "一等奖",
      "candidate_node_count": 128,
      "roll_index": 37,
      "winner_node_number": 42,
      "winner_user_id": 15,
      "winner_character_id": 2118151113
    }
  ]
}
```

第一版只允许节点售完后开奖，因此 `draw_mode` 固定为 `sold_out`。如未来要支持未售完开奖，可再扩展新的 `draw_mode`。

随机数建议使用 PHP `random_int()`。

---

## 10. 实施步骤建议

### 阶段 1：数据结构与基础模型

1. **前置改造（最先做）**：拓宽金额字段精度 —— `kassie_calendar_paps.value` 与 `kassie_calendar_pap_adjustments.value` 均改为 `decimal(8,2)`（有符号），见 §3.4.1。
2. **前置改造（最先做）**：扩展 `calendar_tags.analytics` enum，新增 `lottery`，见 §3.4.2。
3. 新增 lottery / prize / node migrations。
4. 新增 `Lottery`、`LotteryPrize`、`LotteryNode` 模型。
5. 建立与 `Operation` 的关系。
6. 准备系统保留的抽奖专用 tag：`quantifier = 0`，`analytics = lottery`。
7. 统计识别以 lottery 表关联为主，抽奖 tag 作为 UI 和筛选辅助。

### 阶段 2：创建与展示

1. 新增 `LotteryController`。
2. 新增抽奖列表页。
3. 新增抽奖创建页。
4. 创建抽奖时自动创建绑定 operation。
5. 预生成节点。
6. 抽奖详情页显示奖品、节点、进度。

### 阶段 3：购买节点与 PAP 扣除

1. 实现购买接口。
2. 实现可用 PAP 计算。
3. 事务内分配随机节点。
4. 自动创建抽奖 operation 下的 PAP 记录。
5. 写入负数 `PapAdjustment`。
6. 调用 `Pap::recomputeValueFor()`。
7. 页面显示购买结果。

### 阶段 4：售完后手动开奖

1. 实现节点售完后状态变为 `sold_out`。
2. `sold_out` 状态下为 FC / 管理员显示开奖按钮。
3. 支持多奖顺序抽。
4. 支持是否允许重复中奖配置。
5. 保存中奖结果和 `draw_log`。
6. 页面高亮中奖节点。

### 阶段 5：取消退款

1. 实现 FC / 管理员手动取消并退款。
2. 防止重复退款。
3. 保存退款记录和状态。

### 阶段 6：行动审查友好化

抽奖行动会自动出现在审查列表（有 pap 记录即出现），成员明细弹窗几乎零改动即可用，见 §4.4。本阶段只做列表层面的可视化区分：

1. 列表中给抽奖行动加“抽奖”徽标（靠 `analytics = lottery` 或 join `lotteries` 表识别）。
2. 抽奖行动“单 PAP”显示为 `—`，总额负数显示为“消费 PAP”。
3. 成员明细弹窗提供跳转抽奖详情页的链接。
4. 保持手动审查调整不影响节点（只调金额，见 §1.8）。

### 阶段 7：军团统计与导出调整（后续阶段，不纳入第一版）

> 已决策：第一版先把抽奖创建 / 购买 / 开奖 / 退款 / 审查主流程跑通，本阶段延后到主流程稳定后再做。

1. 军团 PAP 页面取消 PAP 类型分布（年度）图表。
2. 在空出位置展示从 `2026-01-01` 起的军团总 PAP、抽奖消耗 PAP、可用 PAP。
3. 支持按月 / 按年查看军团获得 PAP 与抽奖消耗 PAP。
4. 军团 PAP 排名导出增加“抽奖消耗 PAP”和“可用 PAP”列。
5. 导出中的抽奖消耗 PAP 必须与页面筛选时间范围一致。

---

## 11. 风险与注意事项

### 11.1 抽奖行动基础 PAP 必须为 0

当前 `Pap::recomputeValueFor()` 会计算：

```text
operation tag max(quantifier) + adjustments sum
```

如果抽奖 operation 的基础 tag quantifier 不是 0，会导致扣费金额错误。

必须保证：

- 抽奖专用 tag 的 `quantifier = 0`；
- 抽奖创建流程自动绑定该 tag；
- 普通 tag 管理中禁止删除该 tag；
- 普通 tag 管理中禁止把该 tag 的 `quantifier` 改成非 0。

如果未来允许多个抽奖分类 tag，也必须保证所有抽奖 tag 的 `quantifier = 0`，或在 `Pap::recomputeValueFor()` 对 lottery operation 特判基础值为 0。

第一版建议使用单一系统保留抽奖 tag，减少对核心 PAP 逻辑的改动。

### 11.2 统计语义会变化

抽奖消费进入 `kassie_calendar_paps.value` 后，现有总 PAP 会被消费减少。

这符合“可用 PAP”语义，但不再等同于“作战贡献”。

后续应考虑在角色 / 军团统计页区分：

- 获得 PAP；
- 消费 PAP；
- 可用 PAP。

军团 PAP 页面第一版已明确要先调整：取消 PAP 类型分布（年度），改为展示军团总 PAP、抽奖消耗 PAP、可用 PAP；排名导出也必须带上相同时间范围内的抽奖消耗 PAP。

### 11.3 不应通过审查手动购买节点

行动审查只用于 PAP 金额调整。

节点购买必须通过抽奖模块完成，否则无法可靠记录：

- 节点编号；
- 中奖概率；
- 每人上限；
- 是否退款；
- 是否参与开奖。

### 11.4 需要严格防重复退款 / 重复开奖

取消、退款、开奖都属于不可随意重复的动作。

需要通过状态机、事务、唯一约束共同保证：

- 只有 `sold_out` 可以开奖。
- `drawn` 后不能取消和退款。
- `cancelled` 后不能再次退款。

### 11.5 购买后不要再 plain-save `Pap`

`Pap::save()` 每次都会把 `value` 重置为 `tags.max(quantifier)`（对抽奖 tag 即 0）。

因此购买流程必须遵守：

- 首次为购买者建 PAP 行时用 `save()`（此时 value=0，正确）。
- 之后所有扣费 / 退款**只走 `Pap::recomputeValueFor()`**，**绝不再调普通 `save()`**，否则已累积的扣费会被重置为 0，直到下次 recompute 才恢复。

### 11.6 抽奖的“可用 PAP”用独立查询，不要 floor 到 0

抽奖余额计算**不能复用**任何把负值 floor 到 0 的逻辑（例如旧 PAP 商店 API 的 `max(0, total_pap)`，该商店在本分支已停用）。

- 抽奖可用 PAP 用独立查询，负数照实表达。
- 购买校验必须在事务内重新计算余额，不信页面显示值（见 §6.4）。
- floor 到 0 会让透支用户“看起来有 0、实则欠账”，甚至变相抹掉欠账。

---

## 12. 第一版范围建议

第一版建议包含：

- 专用 PAP 抽奖列表 / 创建 / 详情页；
- 绑定特殊 operation；
- 自动绑定系统保留的抽奖专用 tag（`quantifier = 0`，`analytics = lottery`）；
- 多奖品顺序开奖；
- 随机分配节点；
- 每节点价格；
- 每人节点上限；
- 可配置是否允许重复中奖；
- 购买节点自动扣 PAP；
- 节点售完后进入 `sold_out`，由 FC / 管理员手动开奖；
- FC / 管理员取消时全额退款；
- 前端公开节点购买情况和节点归属；
- 抽奖详情页显示当前个人可用 PAP；
- 行动审查可查看并手动调整 PAP。

第一版暂不包含：

- 军团 PAP 页面的「获得 / 抽奖消耗 / 可用」三列统计改造（§7 阶段，延后到主流程跑通后再做）；
- 军团 PAP 排名导出增加抽奖消耗 PAP 列（同上，随阶段 7 一起做）；
- 手动选号；
- EVE Mail 自动通知；
- 自动发奖；
- 奖品库存管理；
- PAP 消费独立钱包；
- 复杂手续费；
- Discord / Slack / Mail 外部通知集成。

---

## 13. 推荐结论

推荐将该功能定义为：

> **PAP 超网抽奖是一种特殊 operation。成员购买节点时，系统在该 operation 下生成负数 PAP 调整；行动审查负责 PAP 扣除审计和事后金额调整，抽奖模块负责节点、奖品、开奖和退款。**

这条路线最大化复用现有 PAP 与行动审查能力，同时保留足够清晰的节点抽奖模型。第一版需要重点控制范围，优先保证扣费、退款、开奖和审计链正确。