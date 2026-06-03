# 06 · PAP Bank API 使用说明

> 面向**外部项目开发者**(抽奖服务、商店服务等)的对接文档。SeAT(`seat-pap`)对外扮演 **PAP 中央账本**,只提供「余额查询 / 实时扣款 / 退款」三类接口 + 一个带签名的用户跳转。抽奖、商店都是调用这套接口的**外部商户**,各自独立成项目。
>
> 设计背景见 [`05-抽奖外移与PAP银行化设计.md`](05-抽奖外移与PAP银行化设计.md)。**对接前务必读第 9 节「当前实现状态」**——写接口目前有一个未修复的阻塞 bug。

---

## 1. 总览

| 接口 | 方法 | 路径 | 用途 | 鉴权 |
|---|---|---|---|---|
| 余额查询(单角色) | GET | `/api/calendar/paps/{character_id}` | 查某角色当前可用 PAP | 读 token |
| 余额查询(批量) | GET | `/api/calendar/paps?characters=id1,id2` | 批量查(≤200) | 读 token |
| 实时扣款 | POST | `/api/calendar/paps/debit` | 交易时扣 PAP | **写 token** |
| 退款 | POST | `/api/calendar/paps/refund` | 退回 PAP | **写 token** |

- Base URL:SeAT 的域名(测试服 `http://ylxh.de`,生产以实际域名为准)。
- 全部 JSON,请求带 `Accept: application/json`、`Content-Type: application/json`。

---

## 2. 鉴权(读 / 写 token 分离)

两个 token **分开存放、可单独轮换**——读 token 泄露也扣不了款,写 token 泄露不影响查询:

| | setting 名 | 用途 | 生成位置 |
|---|---|---|---|
| 读 token | `kassie.calendar.api_token` | 余额查询 + JWT 签名密钥 | 日历→设置→PAP API 设置 |
| 写 token | `kassie.calendar.api_write_token` | debit / refund | 日历→设置→PAP 写接口设置 |

请求头:`Authorization: Bearer <token>`(推荐);写接口也支持 `?token=<token>` 查询参数。生成 token 需 `calendar.setup` 权限。

---

## 3. 用户跳转(JWT 认证)

用户在 SeAT 侧边栏点「超网抽奖」/「PAP 商店」(需 `calendar.view`),SeAT 生成 JWT 并 302 重定向到 `外部地址?token=eyJ...`。

JWT = `Header.Payload.Signature`:
- **Header**:`{"alg":"HS256","typ":"JWT"}`
- **Payload**(抽奖跳转额外带 `balance` 余额快照):

```json
{
  "sub": 120,
  "main_character_id": 2118151113,
  "name": "Akina",
  "balance": 9.00,
  "iat": 1711699200,
  "exp": 1711699260
}
```

| 字段 | 说明 |
|---|---|
| `sub` | **SeAT user_id —— 外部应以此为用户主键,永不变化** |
| `main_character_id` | 主角色 EVE id — 显示用,可能因切换主角色变化 |
| `name` | 主角色名 — 显示用 |
| `balance` | 余额快照,**仅供即时显示,不是可花额度权威**(权威是 debit 时服务端校验);商店跳转无此字段 |
| `iat` / `exp` | 签发 / 过期(签发后 **60 秒**过期) |

- **Signature**:`HMAC-SHA256(base64url(header) + "." + base64url(payload), 读token)`

> ⚠️ **务必用 `sub`(user_id) 做外部用户主键**,不要用 `main_character_id` 或角色 id:用户切换主角色后 character_id 会变,会被误判为新用户。无论用哪个 alt 登录 SeAT,`sub` / `main_character_id` 都相同,JWT 不受登录角色影响。**JWT 不携带"当前登录的那个 alt 角色 id"**——外部也不需要,扣款用 `main_character_id` 即可(见 §5)。

外部验证步骤:拆分 → 验签(HMAC-SHA256,constant-time 比较) → 检查 `exp` → 按 `sub` 查找/创建本地用户、更新 `main_character_id`/`name` → 创建自有 session(时长自定,JWT 60 秒只约束跳转本身)。

验证示例(Python):

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

PHP / Node 同理(`hash_hmac` + `hash_equals` / `crypto.createHmac(...).digest('base64url')`)。

---

## 4. 余额查询

认证:`Authorization: Bearer <读token>` 或 `?token=<读token>`。

**单角色**:
```
GET /api/calendar/paps/{character_id}              # character_id 可为主角色或任意 alt,结果聚合到主角色
GET /api/calendar/paps/{character_id}?since=2026-03-01
GET /api/calendar/paps/{character_id}?breakdown=1
```

成功 (200):
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
| `character_id` | 主角色 ID(已聚合) |
| `user_id` | SeAT user_id(= JWT `sub`) |
| `total_pap` | **当前可用 PAP**(= 出勤 − 消费),`SUM(paps.value)` 后做 `max(0, …)` 非负兜底 |
| `since` | 实际使用的起始日期 |
| `sync_at` | 查询时间 |

**带 `?breakdown=1` 时额外返回三口径**(出勤/消费明细,已实现):
```json
{
  "total_pap": 9.00,
  "attendance_pap": 10.00,
  "consumed_pap": 1.00,
  "available_pap": 9.00
}
```
- `attendance_pap` 出勤 / `consumed_pap` 消费 / `available_pap` 可用(= 出勤−消费,**可为负**,`total_pap` 是它的非负兜底)。恒等:出勤 − 消费 = 可用。

**批量**(定时同步,单次 ≤200):
```
GET /api/calendar/paps?characters=2118151113,2118151114,2118151115
```
```json
{
  "status": "success",
  "data": [ {"character_id": 2118151113, "user_id": 120, "total_pap": 9.00, "since": "2026-01-01"} ],
  "not_found": [2118151115],
  "sync_at": "2026-06-03 02:00:04"
}
```

错误码:200 / 400 缺 `characters` 或超 200 / 401 token 错或缺 / 404 角色未找到 / 503 未配置读 token。

### 4.1 ⏱ 统计的时间范围

- **起始**:全局 PAP 起始日(setting `kassie.calendar.pap_start_date`,**未配则缺省 `2026-01-01`,且强制对齐到月初**)。
- **截止**:**无上限,统计到查询此刻为止**。接口**没有 `until` 参数**,查不到"截止到某天"的历史快照。
- 判定字段是 `paps.join_time`(出勤=参与那场行动的时间;消费=扣款时刻,按交易当下归月)。
- `?since=` **只能把起点往后推,不能往前**:传一个早于全局起始日的值会被钳制回起始日(`max(since, 起始日)`),外部永远查不到起始日之前的 PAP。
- 一句话:**从「全局起始日(默认 2026-01-01)」到「此刻」,中间所有 PAP 净值之和**。

---

## 5. 实时扣款 POST /api/calendar/paps/debit

认证:`Authorization: Bearer <写token>`。

请求体:

| 字段 | 类型 | 必填 | 约束 | 说明 |
|---|---|---|---|---|
| `character_id` | int | ✅ | — | 主角色**或任意 alt**,服务端聚合到主角色 |
| `amount` | number | ✅ | 0.01 ~ 999999.99 | 正数,要扣的 PAP |
| `merchant` | string | ✅ | ≤32 | 商户标识,如 `lottery` / `shop` |
| `idempotency_key` | string | ✅ | ≤128 | **全局唯一**幂等键,重试去重 |
| `ref_group` | string | ❌ | ≤64 | 场次/订单分组,如 `lottery:3`(消费审查按此聚合) |
| `reason` | string | ❌ | ≤255 | 人读明细 |

```json
POST /api/calendar/paps/debit
{ "character_id": 2118151113, "amount": 10.00, "merchant": "lottery",
  "idempotency_key": "lt-3-buy-8842", "ref_group": "lottery:3", "reason": "第3期超网·购买节点#03" }
```

成功 (200):
```json
{ "status": "success", "balance_after": 9.00, "adjustment_id": 12345,
  "idempotency_key": "lt-3-buy-8842", "idempotent_replay": false }
```
> `balance_after` = 扣完后最新余额,**外部拿这个刷新显示**(见 §7 铁律)。`idempotent_replay: true` = 重复请求的回放,没有重复扣。

错误码:200 / 401 写 token 错或缺 / 404 角色未找到 / **409 余额不足(响应含当前 `balance`)** / 422 参数校验失败(amount 非法等) / 503 未配置写 token 或账户正忙(并发锁没抢到,可重试)。

---

## 6. 退款 POST /api/calendar/paps/refund

请求体**与 debit 完全相同**,区别:
- 写**正数**(把 PAP 还回去),**不校验余额**(外部负责"退不超过已扣")。
- `idempotency_key` **独立**(别和扣款的 key 重复)。
- `ref_group` 建议与原扣款**同值**(归入同一场次)。

成功响应同 debit(`balance_after` 是退完后的余额)。

---

## 7. 对接铁律(架构成败关键)

1. **绝不为显示余额轮询 `GET /paps`**。余额只在 debit/refund 时变,响应已回带 `balance_after`,外部本地缓存、靠返回值刷新。若循环查余额,等于把负载压回 SeAT,**外移失去意义**——这是整个减负方案的硬前提。
2. **幂等**:同 `idempotency_key` 重复调结果一致、只扣一次(`idempotent_replay: true` = 命中回放)。
3. **并发**:SeAT 已按用户串行化扣减(`GET_LOCK`),外部正常重试即可,**无需自己加锁**。
4. **余额权威在 SeAT**:JWT 里的 `balance` 快照只供即时显示,能不能花以 debit 服务端校验为准。
5. `character_id` 传主角色或任意 alt 都行,服务端按 SeAT 用户聚合所有小号——**外部用 `main_character_id` 调 debit 就够**。

---

## 8. 对外部商户的要求

- **用户表以 `user_id`(JWT `sub`)做主键**,不要用 character_id。
- 余额展示:靠 debit/refund 回带的 `balance_after` + 跳转 JWT 的 `balance` 快照,**不轮询**。
- 头像:`https://images.evetech.net/characters/{main_character_id}/portrait?size=128`(无需认证)。
- 安全:HTTPS、token 仅存服务端、必须验签(不能只解 payload)、必须检查 `exp`、签名用 constant-time 比较。
- 玩法数据(抽奖节点、中奖、商品)全在外部自管,SeAT 不持有;SeAT 只按 `ref_group` 做**资金审查**(消费审查页)。

---

## 9. ⚠️ 当前实现状态(对接前必读)

契约如上,但**写接口目前还不能直接联调**,以下为 2026-06-03 测试服(MariaDB)实测结论:

1. **debit / refund 有 P0 bug,扣款必 500——上线前必须先修**。
   根因:建商户消费账本锚时 `user_id = 0` 撞 `calendar_operations.user_id` 外键(库里无 id=0 用户),真实数据库上 debit/refund 跑不通。`401`(token 错)、`503`(未配 token)路径已验证正确,但**扣款主流程因此 bug 未通过**。本地 `php -l` 查不出,只有真实 SeAT 才暴露。
2. **限流**:debit/refund 继承 SeAT `api` 中间件组的 `throttle` 限流,高频连发会 `429 Too Many Attempts`。抽奖"下单即调"是高频场景,**可能需给写接口单独放宽限流**。

**结论:外部抽奖/商店项目现在可以照本文档设计对接,但真正联调要等上述 bug 修复 + 限流评估之后。** 进度见 [`04-交接说明.md`](04-交接说明.md)。

---

## 10. 商店恢复路径

debit/refund 是**商户无关**的(`merchant` 字段区分)。商店下线期间接口保留,日后商店恢复时**复用同一套接口**,只需商店端改成"下单调 debit",SeAT 侧零改动。
