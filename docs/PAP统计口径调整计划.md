# PAP 统计口径调整计划

> 阶段 7 设计文档。目标是在 PAP 超网抽奖引入负数 PAP 消费后，统一个人 / 军团 / 排行榜 / API 的统计语义，避免把“获得 PAP”“消费 PAP”“当前净值”混成一个含义不清的数字。

---

## 1. 背景

当前抽奖第一版（阶段 1–6）已经验证闭环：

- 普通行动发放 PAP 仍写入 `kassie_calendar_paps`；
- 行动审查通过追加 `kassie_calendar_pap_adjustments` 并调用 `Pap::recomputeValueFor()` 回写最终值；
- 抽奖购买节点通过负数 `PapAdjustment` 扣费；
- 抽奖取消退款通过正数 `PapAdjustment` 冲回；
- 整行动清零通过反向 `PapAdjustment` 把当前 action 下每个成员的最终值归零。

因此，`kassie_calendar_paps.value` 现在已经不再只是“参加行动获得的 PAP”，而是：

```text
paps.value = 基础 PAP + 全部审查 / 抽奖 adjustments 后的最终净值
```

这个设计对审查、抽奖和商店余额是正确的，但对统计页面来说会产生语义问题：

- 正数表示当前仍保留的 PAP 收益；
- 负数可能表示抽奖消费或其它扣罚；
- `0.00` 可能表示从未获得，也可能表示购买后退款，也可能表示被审查清零；
- 排行榜、趋势图和导出如果继续只叫“PAP”，用户不容易判断它是获得量、消费量还是净值。

阶段 7 的目标不是重写 PAP 模型，而是在**最小修改原则**下把展示和查询口径说明清楚，并逐步补充 earned / spent / balance 语义。

---

## 2. 当前已确认的数据语义

### 2.1 `kassie_calendar_paps.value`

权威语义：

```text
最终净值 / balance
```

来源：

- 普通 PAP 首次写入时，`Pap::save()` 根据 operation tag 的最大 `quantifier` 设置基础值；
- 审查 / 抽奖 / 退款 / 清零写入 `PapAdjustment`；
- `Pap::recomputeValueFor($operationId, $characterId)` 按 `基础 PAP + Σ adjustments` 回写 `paps.value`。

阶段 7 不应改变这个语义。

### 2.2 `kassie_calendar_pap_adjustments.value`

权威语义：

```text
调整流水 / ledger delta
```

典型来源：

- 手动奖励：正数；
- 手动扣罚：负数；
- 抽奖购买节点：负数；
- 抽奖取消退款：正数；
- 整行动清零：与当前 `paps.value` 相反方向的冲正值。

注意：基础 PAP 不是一条 adjustment，而是通过 operation tag quantifier 写入 `paps.value`。因此如果要统计“历史毛收入 / 历史毛消费”，不能只看 adjustments，也不能只看最终的 `paps.value`。

---

## 3. 阶段 7 建议采用的三个展示口径

### 3.1 balance：当前净值

定义：

```text
balance = SUM(paps.value)
```

用途：

- 商店 / 抽奖可用余额；
- 个人和主角色聚合后的当前总 PAP；
- 军团或排行榜的“净 PAP”展示；
- 继续兼容现有 API 的 `total_pap`。

约束：

- 对外商店余额可以继续在 API 边界 `max(0, balance)`，避免负余额被外部消费系统误用；
- 内部统计页面不应无提示地把负数吞掉，否则用户无法解释抽奖消费。

### 3.2 earned：当前正向 PAP

阶段 7 第一版建议定义为：

```text
earned = SUM(CASE WHEN paps.value > 0 THEN paps.value ELSE 0 END)
```

语义：

- 表示当前仍保留为正收益的 PAP；
- 已被审查扣到 0 的行动不再计入 earned；
- 被抽奖消费抵成负值的行不计入 earned。

为什么不先做“历史毛 earned”：

- 基础 PAP 不在 adjustments 表中，需联 operation tags 才能重建；
- 审查可能改变最终认可值，历史毛值容易和当前认可值冲突；
- 为了最小修改，第一版先按最终净值拆正负，不引入复杂 ledger 报表。

### 3.3 spent：当前负向 PAP / 当前消费占用

阶段 7 第一版建议定义为：

```text
spent = SUM(CASE WHEN paps.value < 0 THEN ABS(paps.value) ELSE 0 END)
```

语义：

- 表示当前仍体现在净值中的负向 PAP；
- 未退款、未清零的抽奖购买会体现为 spent；
- 已退款或已清零的抽奖历史，最终 `paps.value = 0`，不再计入当前 spent。

如果未来需要“历史总消费 / 历史退款 / 历史扣罚”报表，应另开 ledger 视图，从 `kassie_calendar_pap_adjustments` 统计，并最好先为 adjustment 增加结构化类型；不要把它混进第一版 spent。

---

## 4. 当前代码入口盘点

### 4.1 个人 PAP 页面

入口：

- `src/Http/Controllers/CharacterController.php`
- `src/resources/views/character/paps.blade.php`
- `src/resources/views/common/includes/ranking_table.blade.php`

当前行为：

- `monthlyPaps`：按用户关联角色集合 `SUM(value)` 分月汇总；
- `thisMonthPaps` / `thisYearPaps`：按关联角色集合 `sum('value')`；
- 周 / 月 / 年排行榜：通过 `refresh_tokens -> users` 聚合到 `COALESCE(u.main_character_id, paps.character_id)`，再 `SUM(paps.value) as qty`；
- 排名表进度条已修复 `0.00` 除零问题。

阶段 7 风险：

- 当前所有数字都是 balance，但页面文案仍是笼统 PAP；
- 图表 y 轴最小值固定为 0，若 balance 出现负数，趋势图可能显示不准确；
- 排行榜如果按 balance 排序，抽奖消费会降低名次，这可能不是用户期待的“出勤排行榜”。

### 4.2 军团 PAP 页面

入口：

- `src/Http/Controllers/CorporationController.php`
- `src/resources/views/corporation/paps.blade.php`

当前行为：

- `getMonthlyTrendJson()`：按 `character_affiliations.corporation_id` 过滤，按月 `SUM(kassie_calendar_paps.value) as qty`；
- `getGroupedRanking()`：按军团角色过滤后，通过 `COALESCE(u.main_character_id, paps.character_id)` 主角色聚合并 `SUM(value) as qty`；
- `getTypeDistribution()`：按 operation tag 的 `analytics` 分组 `SUM(value) as qty`；
- 前端 ranking 支持 CSV 导出，导出列仍只有 rank / character / PAP。

阶段 7 风险：

- 这是最容易受 SQL 聚合影响的区域，不应一上来大改；
- 需要继续保持“先按军团成员角色过滤，再归并主角色”的现有语义；
- 如果加入 adjustments 表，容易因 one-to-many join 放大统计；
- 当前前端 `Math.max(...qty) || 1` 对全 0 有兜底，但如果全部为负数，进度条语义仍不清晰；
- MySQL 聚合表达式在 `ORDER BY` / `HAVING` 中不要依赖聚合别名，必要时写完整表达式。

### 4.3 PAP API / 商店对接

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
- `total_pap` 继续表示对外可用余额，并继续非负兜底；
- 如果需要新字段，必须通过可选参数或新增字段渐进添加，例如：

```json
{
  "total_pap": 12.0,
  "balance": 12.0,
  "earned": 15.0,
  "spent": 3.0
}
```

但不要在没有版本说明的情况下改变 `total_pap` 语义。

### 4.4 行动审查页

入口：

- `src/Http/Controllers/AuditController.php`
- `src/resources/views/audit/`

当前行为：

- `operationsJson()` 使用 `SUM(p.value) as pap_total`；
- 抽奖 action 已特殊展示：单 PAP 显示 `—`，负数总额显示“消费 PAP”；
- `membersJson()` 返回每个成员当前 value 与 adjustment 流水；
- `zero()` 通过反向 adjustment 清零。

阶段 7 建议：

- 审查页已经更接近 ledger 语义，暂不作为阶段 7 第一优先级；
- 保留现有抽奖特殊显示；
- 不要为了统计页面改造而破坏清零和成员明细流水。

### 4.5 抽奖购买余额计算

入口：

- `src/Http/Controllers/LotteryController.php`

当前行为：

- 购买前可用 PAP 按用户关联角色 `SUM(kassie_calendar_paps.value)` 计算；
- 抽奖 operation 下的 `Pap` 行通过 `Pap::firstOrCreate()` 保证存在；
- 扣费 / 退款后通过 `Pap::recomputeValueFor()` 回写。

阶段 7 建议：

- 抽奖余额计算继续使用 balance；
- 不应改为 earned，否则会允许用户重复花掉已消费但历史 earned 仍存在的 PAP。

---

## 5. 主角色聚合规则

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

## 6. 推荐实现顺序

### 6.1 第一步：个人 PAP 页面最小改造

目标：让用户先看懂自己当前 PAP 的组成。

建议改动：

- `CharacterController::paps()` 增加当月 / 当年 breakdown：
  - `earned`；
  - `spent`；
  - `balance`；
- Blade 信息块从两个数字扩展为更明确的展示；
- 文案明确“当前净 PAP / 当前消费 PAP”；
- 月度趋势图第一版可继续显示 balance，但标题或 tooltip 应说明是净值；
- 排行榜第一版建议仍谨慎处理：可以先保留 balance 排序并改文案为“净 PAP 排行”，或新增 earned 排行前先和用户确认。

最小 SQL 表达式示例：

```sql
SUM(CASE WHEN value > 0 THEN value ELSE 0 END) AS earned
SUM(CASE WHEN value < 0 THEN ABS(value) ELSE 0 END) AS spent
SUM(value) AS balance
```

### 6.2 第二步：军团 PAP 页面

目标：让军团趋势、分布、排行不再混淆消费与净值。

建议分两轮：

1. JSON 增加字段但前端尽量保持兼容：
   - `earned`；
   - `spent`；
   - `balance`；
   - 旧字段 `qty` 暂时保留为 balance；
2. 前端逐步改展示：
   - 趋势图标题标明净值；
   - 排行表可增加列或筛选口径；
   - CSV 导出增加列前需确认格式是否会影响使用者。

军团页面风险较高，必须配合宿主数据验证主角色聚合结果。

### 6.3 第三步：API 渐进增强

目标：不破坏商店对接。

建议：

- 默认响应保持 `total_pap` 不变；
- 可选增加 `balance`，与 unclamped 或 clamped 行为需明确；
- 如要暴露 `earned` / `spent`，建议通过 `?breakdown=1` 或文档明确新增字段；
- 商店实际扣费继续基于 balance，不基于 earned。

### 6.4 第四步：历史 ledger 报表（可选）

如果未来用户需要“历史总消费 / 历史退款 / 历史审查扣罚”，再单独设计。

原因：

- 当前 `PapAdjustment.reason` 是文本，不是结构化类型；
- 靠 reason 文案分类不稳定；
- 基础 PAP 不在 adjustment 表中，直接用 adjustments 不能完整还原 earned；
- 最好先增加 `type` / `source` 等结构化字段，再做历史流水统计。

---

## 7. 建议暂不做的事

阶段 7 第一版不建议：

- 不改 `paps.value` 存储语义；
- 不改 `Pap::recomputeValueFor()` 公式；
- 不把抽奖扣费从 PAP 体系拆出去；
- 不直接重写 `CorporationController` 的聚合 SQL；
- 不删除或重建历史 adjustments；
- 不改变现有 API `total_pap` 的默认含义；
- 不引入新的大规模前端框架或 SPA 化改造。

---

## 8. 验证清单

### 8.1 本地静态检查

- `php -l src/Http/Controllers/CharacterController.php`
- `php -l src/Http/Controllers/CorporationController.php`
- `php -l src/Http/Controllers/ApiController.php`

本仓库当前没有完整 PHPUnit / Pest 测试套件，不能假设自动化测试覆盖统计口径。

### 8.2 宿主只读验证

在 SeAT 宿主 `/var/www/seat` 中验证：

```bash
sudo -u www-data php artisan route:list | egrep 'paps|calendar'
sudo -u www-data php artisan tinker
```

建议核对样本：

- 有普通正 PAP 的角色；
- 有抽奖负数消费但未退款的角色；
- 有取消退款回到 0 的角色；
- 有整行动清零记录的角色；
- 同一用户下多个 alt 角色；
- 军团内多个 alt 归并到同一个 main character。

### 8.3 页面验证

- `/character/{character}/paps`
  - 当月 / 当年 earned、spent、balance 是否符合 SQL；
  - 负数或全 0 场景不报错；
  - 排行榜文案是否清楚。
- `/corporation/{corporation}/paps`
  - 趋势图、类型分布、排行榜数据是否符合 SQL；
  - 主角色聚合没有放大或丢失；
  - CSV 导出列与页面一致。
- `/api/calendar/paps/{character_id}`
  - `total_pap` 继续兼容旧语义；
  - 如新增 breakdown，可选字段与文档一致。

---

## 9. 推荐的下一步

阶段 7 建议按以下最小闭环推进：

1. 先确认本设计文档中的 earned / spent / balance 定义；
2. 实现个人 PAP 页面 breakdown，不动军团页面；
3. 在测试服用一个普通行动 + 一个抽奖消费样本验证；
4. 文档记录通过后，再进入军团 PAP 页面；
5. API 只在明确有商店侧需求时再增强。

如果用户只需要避免混淆而不急着改 UI，阶段 7 第一轮也可以先只改文案，把现有数字明确标为“净 PAP”。
