# 06 · PAP Bank API 使用说明

> 面向**外部项目开发者**（抽奖服务、商店服务等）的对接文档。
>
> 最新设计见 [`05-抽奖外移与PAP银行化设计.md`](05-抽奖外移与PAP银行化设计.md)：**抽奖不再购买时实时 debit/refund**，而是在开奖后通过 `settle` 一次性结算为 SeAT operation，并回到行动审查页纠错。`debit/refund` 仍保留，但当前语义降级为**未来 PAP 商店预留接口**。

---

## 1. 总览

| 接口 | 方法 | 路径 | 用途 | 鉴权 |
|---|---|---|---|---|
| 余额查询（单角色） | GET | `/api/calendar/paps/{character_id}` | 查某角色当前可用 PAP | 读 token |
| 余额查询（批量） | GET | `/api/calendar/paps?characters=id1,id2` | 批量查（≤200） | 读 token |
| 抽奖开奖结算 | POST | `/api/calendar/paps/lottery/settle` | 开奖后一次性提交整场扣款 | **写 token** |
| 商店实时扣款（预留） | POST | `/api/calendar/paps/debit` | 未来商店下单时扣 PAP | **写 token** |
| 商店退款（预留） | POST | `/api/calendar/paps/refund` | 未来商店退款 | **写 token** |

- Base URL：SeAT 的域名（测试服 `http://ylxh.de`，生产以实际域名为准）。
- 全部 JSON，请求带 `Accept: application/json`、`Content-Type: application/json`。
- 抽奖主链路是 `lottery/settle`；不要再用 `debit/refund` 实时扣抽奖节点。

---

## 2. 鉴权（读 / 写 token 分离）

两个 token **分开存放、可单独轮换**：读 token 泄露也扣不了款，写 token 泄露不影响查询。

| | setting 名 | 用途 | 生成位置 |
|---|---|---|---|
| 读 token | `kassie.calendar.api_token` | 余额查询 + JWT 签名密钥 | 日历 → 设置 → PAP API 设置 |
| 写 token | `kassie.calendar.api_write_token` | `lottery/settle` + 商店预留 `debit/refund` | 日历 → 设置 → PAP 写接口设置 |

请求头：`Authorization: Bearer <token>`（推荐）。写接口也支持 `?token=<token>` 查询参数。生成 token 需 `calendar.setup` 权限。

---

## 3. 用户跳转（JWT 认证）

用户在 SeAT 侧边栏点「超网抽奖」/「PAP 商店」（需 `calendar.view`），SeAT 生成 JWT 并 302 重定向到：

```text
<外部地址>?token=eyJ...
```

JWT = `Header.Payload.Signature`：

- **Header**：`{"alg":"HS256","typ":"JWT"}`
- **Payload**（抽奖跳转额外带 `balance` 余额快照）：

```json
{
  "sub": 120,
  "main_character_id": 2118151113,
  "name": "Akina",
  "balance": 9.00,
  "iat": 1711699200,
  "exp": 1711699320
}
```

| 字段 | 说明 |
|---|---|
| `sub` | **SeAT user_id —— 外部应以此为用户主键，永不变化** |
| `main_character_id` | 主角色 EVE id，显示用，可能因切换主角色变化 |
| `name` | 主角色名，显示用 |
| `balance` | 抽奖跳转余额快照，仅供显示 / 赊账期参考；开奖后仍以 `settle` 落账为准 |
| `iat` / `exp` | 签发 / 过期（签发后 **120 秒**过期） |

- **Signature**：`HMAC-SHA256(base64url(header) + "." + base64url(payload), 读 token)`
- **务必用 `sub`（user_id）做外部用户主键**，不要用 `main_character_id` 或角色 id。用户切换主角色后 character_id 会变，但 `sub` 不变。
- JWT 不携带“当前登录的那个 alt 角色 id”；外部不需要依赖它。

验证示例（Python）：

```python
import hmac, hashlib, base64, json, time

def base64url_decode(s):
    s += '=' * (4 - len(s) % 4)
    return base64.urlsafe_b64decode(s)

def verify_jwt(token, secret):
    parts = token.split('.')
    if len(parts) != 3:
        return None
    signing_input = f"{parts[0]}.{parts[1]}".encode()
    expected = base64.urlsafe_b64encode(
        hmac.new(secret.encode(), signing_input, hashlib.sha256).digest()
    ).rstrip(b'=').decode()
    if not hmac.compare_digest(expected, parts[2]):
        return None
    payload = json.loads(base64url_decode(parts[1]))
    if payload.get('exp', 0) < time.time():
        return None
    return payload
```

---

## 4. 余额查询

认证：`Authorization: Bearer <读 token>` 或 `?token=<读 token>`。

**单角色**：

```text
GET /api/calendar/paps/{character_id}              # character_id 可为主角色或任意 alt，结果聚合到主角色
GET /api/calendar/paps/{character_id}?since=2026-03-01
GET /api/calendar/paps/{character_id}?breakdown=1
```

成功（200）：

```json
{
  "status": "success",
  "character_id": 2118151113,
  "user_id": 120,
  "total_pap": 9.00,
  "since": "2026-01-01",
  "sync_at": "2026-06-03 08:27:04"
}
```

| 字段 | 说明 |
|---|---|
| `character_id` | 主角色 ID（已聚合） |
| `user_id` | SeAT user_id（= JWT `sub`） |
| `total_pap` | 当前可用 PAP（`SUM(paps.value)` 后做 `max(0, …)` 非负兜底） |
| `since` | 实际使用的起始日期 |
| `sync_at` | 查询时间 |

**带 `?breakdown=1` 时额外返回三口径**：

```json
{
  "total_pap": 9.00,
  "attendance_pap": 10.00,
  "consumed_pap": 1.00,
  "available_pap": 9.00
}
```

- `attendance_pap`：出勤 PAP。
- `consumed_pap`：消费 PAP。
- `available_pap`：当前可用 PAP（= 出勤 − 消费，**可为负**）。
- `total_pap` 是 `available_pap` 的非负兜底。
- 恒等式：`attendance_pap - consumed_pap = available_pap`。

**批量**（定时同步，单次 ≤200）：

```text
GET /api/calendar/paps?characters=2118151113,2118151114,2118151115
```

```json
{
  "status": "success",
  "data": [
    {"character_id": 2118151113, "user_id": 120, "total_pap": 9.00, "since": "2026-01-01"}
  ],
  "not_found": [2118151115],
  "sync_at": "2026-06-03 02:00:04"
}
```

错误码：200 / 400 缺 `characters` 或超 200 / 401 token 错或缺 / 404 角色未找到 / 503 未配置读 token。

### 4.1 统计时间范围

- **起始**：全局 PAP 起始日（setting `kassie.calendar.pap_start_date`，未配则缺省 `2026-01-01`，且强制对齐到月初）。
- **截止**：无上限，统计到查询此刻为止。
- 判定字段是 `paps.join_time`。
- `?since=` 只能把起点往后推，不能往前；早于全局起始日会被钳制回起始日。

---

## 5. 抽奖开奖结算 `POST /api/calendar/paps/lottery/settle`

认证：`Authorization: Bearer <写 token>`。

### 5.1 业务语义

- 一次 `settle` = 一场抽奖开奖结算。
- SeAT 会创建一个 `operation`：`is_consumption=1`，并自动挂 `analytics=lottery` 的保留 tag。
- 开奖人会成为 operation 负责人。
- 每个 participant 会写一条负值 `PapAdjustment(source=lottery)`，然后回写 `paps.value`。
- 抽奖 operation 会进入 `/calendar/audit` 行动审查页；结算后纠错走行动审查奖惩调整，不走 `refund`。
- 余额不足不阻断；允许扣成负值。

### 5.2 请求体

| 字段 | 类型 | 必填 | 约束 | 说明 |
|---|---|---|---|---|
| `ref_group` | string | ✅ | ≤64 | 外部抽奖场次键，如 `lottery:3`；同一场次只能结算一次 |
| `settled_by_character_id` | int | ✅ | — | 开奖人角色 ID，可为其主角色或 alt |
| `title` | string | ✅ | ≤255 | SeAT operation 标题 |
| `idempotency_key` | string | ✅ | ≤128 | 整场结算幂等键，同 key 重试不重复扣；重放 payload 必须与首次一致 |
| `participants` | array | ✅ | 至少 1 项 | 本场实际参与扣款清单 |
| `participants.*.character_id` | int | ✅ | — | 参与者角色 ID，可为主角色或 alt |
| `participants.*.amount` | number | ✅ | 0.01 ~ 999999.99 | 正数，服务端会写成负 PAP |
| `participants.*.reason` | string | ✅ | ≤255 | 人读明细，如购买节点说明 |

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

### 5.3 成功响应

首次结算：

```json
{
  "status": "success",
  "operation_id": 123,
  "idempotency_key": "lottery-3-settle",
  "idempotent_replay": false,
  "participants": [
    {"character_id": 2118151113, "balance_after": -5.00},
    {"character_id": 2118151114, "balance_after": 12.50}
  ]
}
```

重复同 key 重试：

```json
{
  "status": "success",
  "operation_id": 123,
  "idempotency_key": "lottery-3-settle",
  "idempotent_replay": true,
  "participants": [
    {"character_id": 2118151113, "balance_after": -5.00}
  ]
}
```

说明：

- 返回的 `character_id` 是 SeAT 归并后的主角色。
- `balance_after` 是结算后最新可用余额，可为负。
- 同一 `idempotency_key` 命中旧 operation 时不会重复写 adjustment。
- 同一 `ref_group` 已结算时，payload 一致会按幂等回放处理；payload 不一致会返回 409，避免同场重复扣款。
- 同一个 user 的多个 alt 同时出现在 participants 中时，会归并到主角色；服务端保留多条 adjustment 明细，最终合并到同一 `paps` 行。
- 同一主角色在单场内的合计扣款不能超过 `999999.99`，避免超过 PAP 字段范围。

### 5.4 错误码

| 状态码 | 场景 |
|---|---|
| 200 | 成功或幂等重放 |
| 401 | 写 token 错或缺 |
| 404 | 开奖人角色未找到或未绑定 SeAT user |
| 409 | `idempotency_key` 或 `ref_group` 已存在，但 payload 与首次结算不一致 |
| 422 | 请求参数错误，participant 角色未找到 / 未绑定 SeAT user，或单主角色本场合计金额超限 |
| 503 | 写 token 未配置，同场结算锁正忙，或参与者账户正忙 |

---

## 6. 商店预留：实时扣款 `POST /api/calendar/paps/debit`

> 当前抽奖不再调用本接口。它只保留给未来 PAP 商店恢复后使用。

认证：`Authorization: Bearer <写 token>`。

请求体：

| 字段 | 类型 | 必填 | 约束 | 说明 |
|---|---|---|---|---|
| `character_id` | int | ✅ | — | 主角色或任意 alt，服务端聚合到主角色 |
| `amount` | number | ✅ | 0.01 ~ 999999.99 | 正数，要扣的 PAP |
| `merchant` | string | ✅ | ≤32 | 商户标识，如 `shop` |
| `idempotency_key` | string | ✅ | ≤128 | 全局唯一幂等键，重试去重 |
| `ref_group` | string | ❌ | ≤64 | 订单分组 |
| `reason` | string | ❌ | ≤255 | 人读明细 |

```json
{
  "character_id": 2118151113,
  "amount": 10.00,
  "merchant": "shop",
  "idempotency_key": "shop-order-8842",
  "ref_group": "order:8842",
  "reason": "PAP 商店订单 #8842"
}
```

成功（200）：

```json
{
  "status": "success",
  "balance_after": 9.00,
  "adjustment_id": 12345,
  "idempotency_key": "shop-order-8842",
  "idempotent_replay": false
}
```

错误码：200 / 401 写 token 错或缺 / 404 角色未找到 / **409 余额不足** / 422 参数校验失败 / 503 未配置写 token 或账户正忙。

---

## 7. 商店预留：退款 `POST /api/calendar/paps/refund`

请求体与 debit 相同，区别：

- 写**正数**（把 PAP 还回去）。
- 不校验余额，外部负责“退不超过已扣”。
- `idempotency_key` 必须独立，不要和扣款 key 重复。
- `ref_group` 建议与原订单同值。

成功响应同 debit。

---

## 8. 对接铁律

1. **外部用户表以 `user_id`（JWT `sub`）做主键**，不要用 character_id。
2. **抽奖不实时扣款**：购买节点 / 开奖 / 中奖玩法数据都在外部；SeAT 只在开奖后接收 `settle` 结果。
3. **幂等**：`settle.idempotency_key` 按整场重试去重，`ref_group` 防止同场不同 key 重复结算；`debit/refund.idempotency_key` 按单笔交易去重。
4. **主角色聚合**：接口可传主角色或任意 alt，SeAT 会按 user 归并到 main character。
5. **余额显示**：抽奖跳转 JWT 的 `balance` 只是快照；开奖前外部可以自行展示赊账状态，最终以 settle 落账为准。
6. **纠错入口**：抽奖 settle 后的 PAP 修正走 SeAT `/calendar/audit` 行动审查，不走 refund。

---

## 9. 当前实现状态

截至 2026-06-06，`2.0.0` 已部署到生产，当前实现状态为：

- 读 API、写 token、JWT 跳转保留。
- 抽奖主接口已改为 `POST /api/calendar/paps/lottery/settle`。
- `debit/refund` 保留为未来商店预留，当前抽奖不调用。
- 外部消费审查页已废弃；抽奖 operation 回到行动审查页。
- 统计口径不变：出勤 − 消费 = 可用，统计/API 直接读 `paps.value`。

测试服与生产服务端已验证：

1. `settle` 能创建 operation、挂 lottery tag、批量写负值 adjustment。
2. 同一 `idempotency_key` 重试只返回旧 `operation_id`，不重复扣。
3. 同一 `ref_group` 不同 payload 返回 409。
4. 余额不足可 settle 成负值。
5. `/calendar/audit` 可显示抽奖 operation，Web UI 审查弹窗显示正确。
6. 旧 `/calendar/audit/consumption` 返回 404。
7. `GET /api/calendar/paps/{character_id}?breakdown=1` 的恒等式保持成立。

---

## 10. 商店恢复路径

`debit/refund` 是**商户无关**的，`merchant` 字段区分商户。商店下线期间接口保留；日后商店恢复时，可复用同一套接口：

- 下单调用 `debit`。
- 退款调用 `refund`。
- SeAT 侧仍按商店月度 standing operation 归集消费，不进入行动审查页。
