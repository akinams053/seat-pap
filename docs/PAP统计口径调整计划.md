# PAP 统计口径调整计划

> 阶段 7 设计文档。目标是在 PAP 超网抽奖引入消费型 PAP 后，统一个人 / 军团 / 排行榜 / API 的统计语义，避免把「出勤贡献」「消费扣减」「当前余额」混成一个含义不清的数字。

---

## 1. 背景

当前抽奖第一版（阶段 1–6）已经验证闭环：

- 普通行动发放 PAP 仍写入 `kassie_calendar_paps`；
- 行动审查通过追加 `kassie_calendar_pap_adjustments` 并调用 `Pap::recomputeValueFor()` 回写最终值；
- 抽奖购买节点通过负数 `PapAdjustment` 扣费；
- 抽奖取消退款通过正数 `PapAdjustment` 冲回；
- 整行动清零通过反向 `PapAdjustment` 把当前 action 下每个成员的最终值归零。

因此，`kassie_calendar_paps.value` 现在已经不再只是「参加行动获得的 PAP」，而是：

```text
paps.value = 基础 PAP + 全部审查 / 抽奖 adjustments 后的最终净值
```

这个设计对审查、抽奖和商店余额是正确的，但对统计页面来说会产生语义问题：

- 普通行动的正向值表示出勤贡献；
- 抽奖行动的负向值表示消费扣减；
- `0.00` 可能表示从未获得，也可能表示购买后退款，也可能表示被审查清零；
- 排行榜、趋势图和导出如果继续只叫「PAP」，用户不容易判断它是出勤贡献、消费金额还是当前余额。

阶段 7 的目标不是重写 PAP 模型，而是在**最小修改原则**下把展示和查询口径说明清楚，并逐步落地：

```text
出勤 PAP / 消费 PAP / 当前可用 PAP
```

---

## 2. 当前已确认的数据语义

### 2.1 `kassie_calendar_paps.value`

权威语义：

```text
单个 operation + character 的最终净值
```

来源：

- 普通 PAP 首次写入时，`Pap::save()` 根据 operation tag 的最大 `quantifier` 设置基础值；
- 审查 / 抽奖 / 退款 / 清零写入 `PapAdjustment`；
- `Pap::recomputeValueFor($operationId, $characterId)` 按 `基础 PAP + Σ adjustments` 回写 `paps.value`。

阶段 7 不应改变这个存储语义。

### 2.2 `kassie_calendar_pap_adjustments.value`

权威语义：

```text
调整流水 / ledger delta
```

典型来源：

- 普通行动手动奖励：正数；
- 普通行动手动扣罚：负数；
- 抽奖购买节点：负数；
- 抽奖取消退款：正数；
- 整行动清零：与当前 `paps.value` 相反方向的冲正值。

注意：基础 PAP 不是一条 adjustment，而是通过 operation tag quantifier 写入 `paps.value`。因此如果要统计「历史原始发放 / 历史总消费 / 历史退款」，不能只看 adjustments，也不能只看最终的 `paps.value`。

---

## 3. 阶段 7 采用的三个业务口径

### 3.1 出勤 PAP

定义：

```text
出勤 PAP = 普通行动最终认可的 PAP
```

计入范围：

- 普通 operation 发放的基础 PAP；
- 普通 operation 上通过行动审查追加的奖励；
- 普通 operation 上通过行动审查扣除后的最终认可值；
- 普通 operation 整行动清零后，该行动出勤 PAP 为 0。

不计入范围：

- 抽奖购买消耗；
- 抽奖取消退款；
- 未来商店消费；
- 其它专门消费型 operation。

阶段 7 第一版建议使用「普通行动 + 正向最终值」计算：

```sql
SUM(
    CASE
        WHEN l.id IS NULL AND p.value > 0 THEN p.value
        ELSE 0
    END
) AS attendance_pap
```

其中 `l` 是通过 `kassie_calendar_lotteries.operation_id = p.operation_id` 关联出来的抽奖记录。

语义：

- 它不是原始历史毛发放；
- 它是经过审查、扣罚、清零后的「当前认可出勤贡献」；
- 军团成员贡献排行默认应按它排序。

### 3.2 消费 PAP

定义：

```text
消费 PAP = 当前仍然生效的消费型 PAP 净消耗
```

当前项目里的来源：

- 抽奖行动购买节点产生的负数 PAP。

未来可扩展来源：

- 商店重启后的商品购买扣费；
- 其它明确标记为消费型的 operation。

阶段 7 第一版建议使用「抽奖行动 + 负向最终值」计算：

```sql
SUM(
    CASE
        WHEN l.id IS NOT NULL AND p.value < 0 THEN ABS(p.value)
        ELSE 0
    END
) AS consumed_pap
```

语义：

- 这是当前净消费，不是历史累计消费；
- 抽奖取消并退款后，抽奖 operation 下最终 `paps.value = 0`，因此不再计入当前消费 PAP；
- 如果未来需要「历史消费 / 历史退款」报表，应另做 ledger 视图，不混进阶段 7 第一版。

### 3.3 当前可用 PAP

定义：

```text
当前可用 PAP = 当前可以继续用于抽奖 / 商店消费的 PAP
```

在业务模型中：

```text
当前可用 PAP = 出勤 PAP - 消费 PAP
```

在现有存储模型中，当前可用 PAP 继续通过所有 `paps.value` 求和得到：

```sql
SUM(p.value) AS available_pap
```

用途：

- 抽奖购买余额判断；
- 未来商店消费余额判断；
- 兼容现有 API 的 `total_pap`；
- 个人页面展示「还剩多少可用」。

约束：

- 内部统计页面可以显示负数，以便解释透支或扣罚场景；
- 对外 API / 商店消费入口可以继续 `max(0, available_pap)` 兜底，避免外部系统收到负余额。

---

## 4. 为什么不只按正负号拆分

早期技术草案曾考虑：

```text
earned  = SUM(value > 0 的部分)
spent   = SUM(value < 0 的部分)
balance = SUM(value)
```

经过业务讨论后，阶段 7 应改为按「来源类型 + value 方向」共同判断：

```text
普通行动的正向最终值 => 出勤 PAP
抽奖 / 商店等消费型行动的负向最终值 => 消费 PAP
所有最终值求和 => 当前可用 PAP
```

原因：

- 正数不一定永远代表出勤，未来可能有退款 / 补偿型消费记录；
- 负数不一定永远代表消费，普通行动过度扣罚可能产生负值；
- 军团贡献排行应避免被抽奖 / 商店消费拉低；
- 抽奖 / 商店余额判断必须扣除已经消费的 PAP。

因此阶段 7 第一版应优先以 `kassie_calendar_lotteries.operation_id` 区分抽奖 operation，而不是只看 `paps.value` 的正负号。

---

## 5. 当前代码入口盘点与调整方向

### 5.1 个人 PAP 页面

入口：

- `src/Http/Controllers/CharacterController.php`
- `src/resources/views/character/paps.blade.php`
- `src/resources/views/common/includes/ranking_table.blade.php`

当前行为：

- `monthlyPaps`：按用户关联角色集合 `SUM(value)` 分月汇总；
- `thisMonthPaps` / `thisYearPaps`：按关联角色集合 `sum('value')`；
- 周 / 月 / 年排行榜：通过 `refresh_tokens -> users` 聚合到 `COALESCE(u.main_character_id, paps.character_id)`，再 `SUM(paps.value) as qty`；
- 排名表进度条已修复 `0.00` 除零问题。

阶段 7 调整方向：

- `CharacterController::paps()` 增加当月 / 当年 breakdown：
  - `attendance_pap`；
  - `consumed_pap`；
  - `available_pap`；
- Blade 信息块改为明确展示：
  - 本月出勤 PAP；
  - 本月消费 PAP；
  - 本月当前可用 PAP；
  - 本年同理；
- 月度趋势图第一版可继续显示当前可用 PAP，但标题或 tooltip 必须说明是「净值 / 可用余额」；
- 更推荐后续把趋势图调整为出勤 PAP 主线，同时 tooltip 显示消费 PAP 和当前可用 PAP。

### 5.2 军团 PAP 页面

入口：

- `src/Http/Controllers/CorporationController.php`
- `src/resources/views/corporation/paps.blade.php`

当前行为：

- `getMonthlyTrendJson()`：按 `character_affiliations.corporation_id` 过滤，按月 `SUM(kassie_calendar_paps.value) as qty`；
- `getGroupedRanking()`：按军团角色过滤后，通过 `COALESCE(u.main_character_id, paps.character_id)` 主角色聚合并 `SUM(value) as qty`；
- `getTypeDistribution()`：按 operation tag 的 `analytics` 分组 `SUM(value) as qty`；
- 前端 ranking 支持 CSV 导出，导出列仍只有 rank / character / PAP。

阶段 7 调整方向：

- `getGroupedRanking()` 增加：
  - `attendance_pap`；
  - `consumed_pap`；
  - `available_pap`；
  - 旧字段 `qty` 可暂时保留为 `available_pap`，以兼容前端过渡；
- 军团成员主排行榜默认按：

```text
出勤 PAP DESC, 当前可用 PAP DESC
```

- 表格建议改为：

```text
排名 | 角色 | 出勤 PAP | 消费 PAP | 当前可用 PAP
```

- CSV 导出同步增加列，但应在变更说明中提示导出格式已变化；
- `getMonthlyTrendJson()` 建议返回三列，前端第一版可默认画出勤 PAP 趋势；
- `getTypeDistribution()` 暂不作为第一优先级大改，先改标题 / tooltip，避免 tag join 或多对多关系放大统计。

风险提示：

- 军团页面必须继续保持「先按军团成员角色过滤，再归并主角色」的现有语义；
- 如果加入新 join，必须避免 one-to-many join 放大 totals；
- MySQL 聚合表达式在 `ORDER BY` / `HAVING` 中不要依赖聚合别名，必要时写完整表达式。

### 5.3 PAP API / 商店对接

入口：

- `src/Http/Controllers/ApiController.php`
- `docs/PAP商店对接指南.md`

当前行为：

- 按角色找到 SeAT user；
- 使用 `associatedCharacterIds()` 做主角色下所有角色聚合；
- `SUM(value)` 后返回 `total_pap = max(0.0, totalPap)`；
- `since` 默认 `2026-01-01`。

阶段 7 建议：

- 第一版不破坏现有 `total_pap` 字段；
- `total_pap` 继续表示对外可消费额度，即 `max(0, available_pap)`；
- 如果需要新字段，必须通过可选参数或新增字段渐进添加，例如：

```json
{
  "total_pap": 12.0,
  "attendance_pap": 30.0,
  "consumed_pap": 18.0,
  "available_pap": 12.0
}
```

不要在没有版本说明的情况下改变 `total_pap` 语义。

### 5.4 行动审查页

入口：

- `src/Http/Controllers/AuditController.php`
- `src/resources/views/audit/`

当前行为：

- `operationsJson()` 使用 `SUM(p.value) as pap_total`；
- 抽奖 action 已特殊展示：单 PAP 显示 `—`，负数总额显示「消费 PAP」；
- `membersJson()` 返回每个成员当前 value 与 adjustment 流水；
- `zero()` 通过反向 adjustment 清零。

阶段 7 建议：

- 审查页已经更接近 ledger 语义，暂不作为阶段 7 第一优先级；
- 保留现有抽奖特殊显示；
- 后续可在审查页文案中区分：
  - 普通行动：出勤 PAP；
  - 抽奖行动：消费 PAP；
- 不要为了统计页面改造而破坏清零和成员明细流水。

### 5.5 抽奖购买余额计算

入口：

- `src/Http/Controllers/LotteryController.php`

当前行为：

- 购买前可用 PAP 按用户关联角色 `SUM(kassie_calendar_paps.value)` 计算；
- 抽奖 operation 下的 `Pap` 行通过 `Pap::firstOrCreate()` 保证存在；
- 扣费 / 退款后通过 `Pap::recomputeValueFor()` 回写。

阶段 7 建议：

- 抽奖余额计算继续使用当前可用 PAP；
- 不应改为出勤 PAP，否则会允许用户重复花掉已经消费过的 PAP。

---

## 6. 主角色聚合规则

阶段 7 必须继续遵守：

```text
用户维度统计优先按 SeAT user.main_character_id 聚合该用户所有 alt 角色。
```

建议规则：

1. 个人页面：继续使用当前角色所属用户的 `associatedCharacterIds()`；
2. 全局排行榜：继续使用 `refresh_tokens -> users -> main_character_id`，无用户时 fallback 到原角色 ID；
3. 军团页面：保持当前语义，即先按 `character_affiliations.corporation_id` 过滤在军团内的角色，再聚合到 main character；
4. API：继续按目标角色所属 SeAT user 的所有关联角色聚合；
5. 不要把 main character join 改成 inner join，避免未绑定 / 异常角色直接丢失。

---

## 7. 需要先确认的数据边界

### 7.1 普通行动被扣成负数

当前代码层面 `Pap::recomputeValueFor()` 没有阻止普通行动被扣成负数：

```text
paps.value = 基础 PAP + Σ adjustments
```

例如：

```text
基础 PAP = 10
审查扣罚 = -15
最终 paps.value = -5
```

按阶段 7 三分类，这个 `-5` 既不是出勤 PAP，也不是抽奖 / 商店消费 PAP。

建议阶段 7 先做只读巡检：

```text
普通行动是否存在 paps.value < 0
```

如果不存在，可以保持公式简单：

```text
当前可用 PAP = 出勤 PAP - 消费 PAP
```

如果存在，需要单独决策：

1. 业务上是否允许普通行动最终扣到负数；
2. 是否应在未来限制普通行动扣罚最多扣到 0；
3. 是否需要新增第四类「处罚 PAP / 审查扣罚 PAP」。

阶段 7 第一版不建议未经确认就改 `Pap::recomputeValueFor()`，避免影响既有审查行为。

### 7.2 抽奖行动出现正数

抽奖取消退款后，抽奖 operation 的最终 `paps.value` 应回到 0；正常不应长期出现正数。

建议巡检：

```text
抽奖行动是否存在 paps.value > 0
```

如果存在，需要确认是否为手动补偿、异常退款或测试数据。

---

## 8. 推荐实现顺序

### 8.1 第一步：更新文档与确认口径

目标：把项目文档统一到以下业务口径：

```text
出勤 PAP / 消费 PAP / 当前可用 PAP
```

同步范围：

- 本文档；
- PAP 超网抽奖功能计划；
- PAP 超网抽奖交接说明；
- PAP 商店对接指南；
- FC 指南；
- 文档索引。

### 8.2 第二步：测试服只读数据巡检

在 SeAT 宿主 `/var/www/seat` 中验证：

```bash
sudo -u www-data php artisan tinker
```

建议核对样本：

- 普通行动是否存在最终负值；
- 抽奖行动是否存在最终正值；
- 已退款抽奖是否回到 0；
- `出勤 PAP - 消费 PAP` 是否等于 `SUM(value)`；
- 同一用户下多个 alt 角色是否正确归并到 main character；
- 军团页面「先按军团成员过滤，再主角色归并」是否仍成立。

### 8.3 第三步：个人 PAP 页面最小改造

目标：让成员先看懂自己的 PAP 组成。

建议改动：

- `CharacterController::paps()` 增加当月 / 当年 breakdown；
- Blade 信息块展示出勤 PAP、消费 PAP、当前可用 PAP；
- 月度趋势标题先明确「当前可用 PAP」或改为「出勤 PAP 趋势」；
- 排行榜第一版可先保留现状并改文案，或等军团排名一起处理。

### 8.4 第四步：军团 PAP 页面

目标：让军团趋势、分布、排行不再混淆出勤贡献与消费余额。

建议分两轮：

1. JSON 增加字段但前端尽量保持兼容：
   - `attendance_pap`；
   - `consumed_pap`；
   - `available_pap`；
   - 旧字段 `qty` 暂时保留为 `available_pap`；
2. 前端逐步改展示：
   - 排行表默认按出勤 PAP 排；
   - 增加出勤 PAP / 消费 PAP / 当前可用 PAP 列；
   - CSV 导出同步更新；
   - 趋势图标题和 tooltip 明确口径。

军团页面风险较高，必须配合宿主数据验证主角色聚合结果。

### 8.5 第五步：API 渐进增强

目标：不破坏商店对接。

建议：

- 默认响应保持 `total_pap` 不变；
- `total_pap` 继续表示 `max(0, available_pap)`；
- 如要暴露 breakdown，建议通过 `?breakdown=1` 或文档明确新增字段；
- 商店实际扣费继续基于当前可用 PAP，不基于出勤 PAP。

### 8.6 第六步：历史 ledger 报表（可选）

如果未来用户需要「历史总消费 / 历史退款 / 历史审查扣罚」，再单独设计。

原因：

- 当前 `PapAdjustment.reason` 是文本，不是结构化类型；
- 靠 reason 文案分类不稳定；
- 基础 PAP 不在 adjustment 表中，直接用 adjustments 不能完整还原出勤 PAP；
- 最好先增加 `type` / `source` 等结构化字段，再做历史流水统计。

---

## 9. 建议暂不做的事

阶段 7 第一版不建议：

- 不改 `paps.value` 存储语义；
- 不改 `Pap::recomputeValueFor()` 公式；
- 不把抽奖扣费从 PAP 体系拆出去；
- 不直接重写 `CorporationController` 的全部聚合 SQL；
- 不删除或重建历史 adjustments；
- 不改变现有 API `total_pap` 的默认含义；
- 不把普通行动负值强行归类为消费 PAP；
- 不引入新的大规模前端框架或 SPA 化改造。

---

## 10. 验证清单

### 10.1 本地静态检查

- `php -l src/Http/Controllers/CharacterController.php`
- `php -l src/Http/Controllers/CorporationController.php`
- `php -l src/Http/Controllers/ApiController.php`

本仓库当前没有完整 PHPUnit / Pest 测试套件，不能假设自动化测试覆盖统计口径。

### 10.2 宿主只读验证

在 SeAT 宿主 `/var/www/seat` 中验证：

```bash
sudo -u www-data php artisan route:list | egrep 'paps|calendar'
sudo -u www-data php artisan tinker
```

建议核对样本：

- 有普通正 PAP 的角色；
- 有普通行动审查奖励的角色；
- 有普通行动审查扣罚的角色；
- 有抽奖负数消费但未退款的角色；
- 有取消退款回到 0 的角色；
- 有整行动清零记录的角色；
- 同一用户下多个 alt 角色；
- 军团内多个 alt 归并到同一个 main character。

### 10.3 页面验证

- `/character/{character}/paps`
  - 当月 / 当年出勤 PAP、消费 PAP、当前可用 PAP 是否符合 SQL；
  - 负数或全 0 场景不报错；
  - 页面文案是否清楚。
- `/corporation/{corporation}/paps`
  - 排行榜默认是否按出勤 PAP 排；
  - 趋势图、类型分布、排行榜数据是否符合 SQL；
  - 主角色聚合没有放大或丢失；
  - CSV 导出列与页面一致。
- `/api/calendar/paps/{character_id}`
  - `total_pap` 继续兼容旧语义；
  - 如新增 breakdown，可选字段与文档一致。

---

## 11. 推荐的下一步

阶段 7 建议按以下最小闭环推进：

1. 先确认并提交本文档中的「出勤 PAP / 消费 PAP / 当前可用 PAP」定义；
2. 在测试服只读巡检普通行动负值、抽奖正值和主角色聚合样本；
3. 实现个人 PAP 页面 breakdown，不动军团页面；
4. 用一个普通行动 + 一个抽奖消费样本验证；
5. 文档记录通过后，再进入军团 PAP 页面；
6. API 只在明确有商店侧需求时再增强。

如果用户只需要避免混淆而不急着改 UI，阶段 7 第一轮也可以先只改文案，把现有数字明确标为「当前可用 PAP / 净 PAP」。
