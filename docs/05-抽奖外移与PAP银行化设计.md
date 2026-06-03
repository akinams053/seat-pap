# 05 · 抽奖外移与 PAP 银行化设计

> 本文档是 `feat/pap-bank-externalize` 当前**有效**设计，已合并旧 05「抽奖外移 / PAP 银行化」与旧 07「抽奖重做为行动」两份文档。
>
> 当前结论：抽奖玩法外移到独立服务；SeAT 侧不再做节点 / 开奖 UI，而是保留 PAP 中央账本能力，并在**开奖后**通过 `settle` 把一场抽奖结算成一个可审查的 operation。
>
> 测试服状态（2026-06-03）：`dev-feat/pap-bank-externalize` @ `1f6d70b` 已部署到 `/var/www/seat`，migration / settle / PAP API / Web UI 行动审查弹窗均已验证通过。

---

## 1. 目标与边界

### 1.1 目标

- 把抽奖页面、节点、中奖、开奖逻辑从 SeAT 插件中移到外部服务。
- SeAT 继续作为 PAP 权威账本：余额查询、结算入账、统计、审查。
- 抽奖结算后仍能由 FC 在 SeAT 的**行动审查**里纠错。
- 保留 `debit/refund` 写接口作为未来 PAP 商店恢复时的预留能力。

### 1.2 不在本仓库范围内

- 外部抽奖服务本体：UI、节点、购买、开奖、中奖记录。
- 外部抽奖玩法审查：节点归属、中奖概率、开奖算法等。
- PAP 商店本体。

---

## 2. 当前有效模型

SeAT 中通过 `calendar_operations.is_consumption` 与 `calendar_tags.analytics = lottery` 区分三类 operation：

| 类型 | `is_consumption` | lottery tag | 统计口径 | 行动审查 |
|---|---:|---:|---|---|
| 普通出勤行动 | `0` | 否 | 出勤 PAP | ✅ 显示 |
| 抽奖结算行动 | `1` | 是 | 消费 PAP | ✅ 显示 |
| 商店消费锚 | `1` | 否 | 消费 PAP | ❌ 不显示 |

关键点：

- **消费统计仍按 `is_consumption` 判定**，因此抽奖与商店都会进入消费 PAP。
- **行动审查过滤**是：普通出勤行动 + 抽奖行动；商店消费锚排除。
- 抽奖行动使用保留 tag：

```text
name = PAP 抽奖 / Lottery
analytics = lottery
quantifier = 0
```

`quantifier = 0` 保证抽奖基础 PAP 为 0，最终消费来自负值 adjustment。

---

## 3. 抽奖新流程

```text
用户在 SeAT 点击“超网抽奖”
  → SeAT 生成 JWT 并跳转外部抽奖服务
  → 外部服务负责购买 / 节点 / 开奖
  → 开奖后，外部服务调用 SeAT settle 接口
  → SeAT 创建一个抽奖 operation
  → SeAT 写入参与者负值 PapAdjustment，并 recompute 回写 paps.value
  → 该 operation 出现在 Calendar → 行动审查
  → FC 如需纠错，使用现有 PAP 审查奖惩调整
```

与旧 05 的重要区别：

- 抽奖**不再购买时实时 debit**。
- 抽奖**不再写月度消费锚**。
- 抽奖**不再需要外部消费审查页**。
- 一场抽奖 = 一个 operation，开奖人 = operation 负责人。

---

## 4. settle 接口

详见 [`06-API使用说明.md`](06-API使用说明.md)。摘要如下：

```http
POST /api/calendar/paps/lottery/settle
Authorization: Bearer <WRITE_TOKEN>
Content-Type: application/json
```

```json
{
  "ref_group": "lottery:3",
  "settled_by_character_id": 2118151113,
  "title": "第3期超网抽奖",
  "idempotency_key": "lottery-3-settle",
  "participants": [
    {"character_id": 2118151113, "amount": 10.00, "reason": "押注节点 #03, #07"},
    {"character_id": 2118151114, "amount": 5.00, "reason": "押注节点 #11"}
  ]
}
```

### 4.1 服务端行为

1. 校验写 token。
2. 校验请求体。
3. 用 `ref_group` / `idempotency_key` 做整场幂等与冲突保护。
4. 将 `settled_by_character_id` 解析到 SeAT user 与 main character。
5. 创建 operation：
   - `is_consumption = 1`
   - `user_id = 开奖人所属 user`
   - `fc_character_id = 开奖人 main character`
   - `consumption_key = lottery-settle:<hash(idempotency_key)>`
6. 自动绑定 lottery 保留 tag。
7. 每个 participant：
   - 角色可传 main 或任意 alt；服务端归并到 main character。
   - 写负值 `PapAdjustment(source=lottery, ref_group=..., reason=...)`。
   - 调用 `Pap::recomputeValueFor()` 回写 `paps.value`。
8. 返回 `operation_id`、`idempotent_replay`、每个 main character 的 `balance_after`。

### 4.2 幂等与冲突

- 同 `idempotency_key` + 同 payload：返回旧 operation，不重复扣。
- 同 `ref_group` 但 payload 不同：返回 `409 Conflict`。
- 单场同一 user 的多个 alt 会归并到同一 main character，但 adjustment 明细会保留外部传入的多条记录。

### 4.3 余额不足

抽奖 settle **允许扣成负值**。

原因：开奖时外部抽奖结果已经成立，SeAT 侧应如实落账；余额不足或争议由 FC 在行动审查中后续纠错。对外余额查询的 `total_pap` 仍会对负数做 `max(0, available)` 兜底。

---

## 5. PAP 统计口径

PAP 最终值仍以 `kassie_calendar_paps.value` 为单一来源：

```text
paps.value = 基础 PAP + 全部 PapAdjustment 后的最终净值
```

三口径：

```text
出勤 PAP = SUM(is_consumption = 0 的 paps.value)
消费 PAP = SUM(is_consumption = 1 的 -paps.value)
当前可用 = SUM(全部 paps.value)

出勤 PAP - 消费 PAP = 当前可用
```

注意：

- 抽奖 operation 是 `is_consumption=1`，因此其负值会变成正的消费 PAP。
- 普通行动被 FC 扣成负数时，仍作为出勤 PAP 的惩罚值体现。
- 所有角色 / 军团 / API 汇总优先按 SeAT user 的 main character 聚合全部 alt。

---

## 6. 数据结构与迁移

当前相关迁移：

- `2026_05_29_100000_widen_pap_value_precision`
  - 将 PAP/adjustment 金额精度扩展到 `decimal(8,2)`。
- `2026_05_29_100100_extend_calendar_tags_analytics_enum`
  - `calendar_tags.analytics` 增加 `lottery`。
- `2026_05_29_100200_create_lottery_tables`
  - 保留旧内置抽奖三表，用于历史归档 / 兼容。
- `2026_05_30_000000_cleanup_deprecated_notification_settings`
  - 清理废弃通知设置。
- `2026_06_01_000000_add_bank_ledger_columns`
  - `kassie_calendar_pap_adjustments` 增加：
    - `source`
    - `external_ref`
    - `ref_group`
  - `calendar_operations` 增加：
    - `is_consumption`
    - `consumption_key`
  - 回填历史抽奖 operation 为消费，并把历史抽奖 adjustment 标记为 `source=lottery`。

---

## 7. 商店预留：debit / refund

`POST /api/calendar/paps/debit` 与 `POST /api/calendar/paps/refund` 当前**不用于抽奖**，只保留给未来 PAP 商店恢复。

保留原因：

- 写 token / 幂等 / 用户级锁 / 余额校验逻辑已经具备。
- 商店恢复时可直接以 `merchant=shop` 接入。
- 商店消费锚是 `is_consumption=1` 且无 lottery tag，因此计入消费，但不出现在行动审查页。

注意：旧的 `standingFor user_id=0` 外键 bug 已修复；当前抽奖 settle 主链路不依赖 standing operation。

---

## 8. 测试服验证记录（2026-06-03）

测试服：`ylxh.de`，SeAT 根目录 `/var/www/seat`，数据库 MariaDB 10.6.x。

已验证：

- ✅ Composer 切到 `dev-feat/pap-bank-externalize` @ `1f6d70b`。
- ✅ 以 `www-data` 成功执行新增 migration。
- ✅ 发布静态资源并清理缓存。
- ✅ `POST /api/calendar/paps/lottery/settle` 成功创建验证 operation `181`。
- ✅ 验证 operation：
  - `is_consumption = 1`
  - lottery tag
  - `paps.value = -0.03`
  - alt + main 归并到 main character `2121487383`
- ✅ 同 payload 重放返回 `idempotent_replay = true`。
- ✅ 同 `ref_group` 不同 payload 返回 `409 Conflict`。
- ✅ `GET /api/calendar/paps/{alt}?breakdown=1` 返回：
  - `consumed_pap = 0.03`
  - `available_pap = -0.03`
  - `total_pap = 0`
- ✅ 旧 `/calendar/audit/consumption` 返回 `404`。
- ✅ 用户在 Web UI 行动审查中确认 `CLAUDE_VERIFY ...` 抽奖行动的 PAP 审查弹窗正常：类型=抽奖、单 PAP=0、人数=1、总额=-0.03、奖惩历史正确。

验证时遇到的非业务问题：

- `php artisan` 需用 `sudo -u www-data`，不能直接用 `ubuntu` 用户读取 `.env`。
- 连续本机 curl 曾触发 `api` 组 throttle，返回 `429`；正常抽奖 settle 是低频单次调用，通常不会遇到。外部服务仍应按 HTTP 规范处理 `Retry-After`。

---

## 9. 上线前建议

- 外部抽奖服务：
  - settle 必须使用稳定 `idempotency_key`。
  - 同一场必须使用稳定 `ref_group`。
  - 遇到 `409` 要停止重试并人工对账。
  - 遇到 `429` 按 `Retry-After` 等待，不要无间隔循环。
- SeAT 插件：
  - 普通 operation 创建 / PAP 发放 / 普通行动审查可再做一次 UI 回归。
  - 如测试服验证数据不再需要，可清理 `CLAUDE_VERIFY` operation。
- 将来恢复商店时，需要重新评估“抽奖赊账 + 商店实时扣”的双花边界。当前商店下线，抽奖 settle 主链路不受影响。
