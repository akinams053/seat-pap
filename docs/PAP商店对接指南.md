# PAP 商店对接指南

本文档面向商店开发者，说明 SeAT PAP 插件与 PAP 商店之间的完整交互协议。

---

## 架构概览

```
┌──────────────┐       ┌──────────────────┐       ┌──────────────┐
│   浏览器      │       │  SeAT 服务器       │       │  商店服务器    │
│  （成员）     │       │  (seat-pap 插件)   │       │              │
└──────┬───────┘       └────────┬─────────┘       └──────┬───────┘
       │                        │                         │
       │  1. 点击侧边栏          │                         │
       │   "PAP 商店"           │                         │
       │ ─────────────────────> │                         │
       │                        │                         │
       │                        │ 2. 生成 JWT              │
       │                        │    (签名密钥=API Token)   │
       │                        │                         │
       │  3. 302 重定向          │                         │
       │     shop.com/auth      │                         │
       │     ?token=eyJ...      │                         │
       │ ────────────────────────────────────────────────> │
       │                        │                         │
       │                        │  4. 商店验证 JWT 签名      │
       │                        │     创建 session          │
       │                        │                         │
       │                        │  5. 查询 PAP（按需）       │
       │                        │  GET /api/calendar/paps  │
       │                        │  Authorization: Bearer   │
       │                        │ <─────────────────────── │
       │                        │                         │
       │                        │  6. 返回聚合后 PAP 数据    │
       │                        │ ──────────────────────>  │
       │                        │                         │
       │  7. 展示商店页面 + PAP 余额                        │
       │ <──────────────────────────────────────────────── │
```

涉及三个角色：

| 角色 | 职责 |
|------|------|
| **SeAT (seat-pap 插件)** | 管理 PAP 数据、生成 JWT、提供 PAP API |
| **商店服务器** | 验证 JWT、管理商店会话、调用 API 查询 PAP |
| **浏览器** | 用户操作界面，负责跳转 |

---

## 第一部分：共享密钥

SeAT 和商店之间**共享一个密钥**，即插件设置页生成的 **API Token**。

- 在 SeAT 中：**日历 → 设置 → PAP API 设置 → 生成 Token**
- Token 是一个 48 字符的随机字符串
- 管理员将此 Token 配置到商店服务器的环境变量中

此 Token 有两个用途：

1. **JWT 签名密钥** — 用于跳转时的身份认证
2. **API Bearer Token** — 用于商店服务器调用 PAP 查询接口

---

## 第二部分：用户跳转（JWT 认证）

### 触发方式

用户在 SeAT 侧边栏点击 **"PAP 商店"**，插件生成 JWT 并 302 重定向到商店。

### JWT 结构

```
Header.Payload.Signature
```

**Header**（固定）：

```json
{
  "alg": "HS256",
  "typ": "JWT"
}
```

**Payload**：

```json
{
  "sub": 120,
  "main_character_id": 2118151113,
  "name": "Akina",
  "iat": 1711699200,
  "exp": 1711699260
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `sub` | int | **SeAT user_id** — 商店应以此作为用户主键。此值永不变化，即使用户切换主角色 |
| `main_character_id` | int | 当前主角色的 EVE character_id — 用于显示头像和名称，可能因用户切换主角色而变化 |
| `name` | string | 当前主角色名 — 显示用途 |
| `iat` | int | 签发时间（Unix 时间戳） |
| `exp` | int | 过期时间（签发后 60 秒） |

**Signature**：

```
HMAC-SHA256(base64url(header) + "." + base64url(payload), API_Token)
```

### 跳转 URL 格式

```
https://shop.example.com/auth?token=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjEyMCwi...
```

管理员在插件设置页配置商店的认证地址（如 `https://shop.example.com/auth`），插件将 JWT 作为 `token` 查询参数附加。

### 关于 user_id 和 main_character_id

为什么不用 `main_character_id` 做商店的用户主键？

```
第1天：用户主角色 = A (ID: 1001)  →  商店创建用户 1001
第2天：用户在 SeAT 把主角色切换为 B (ID: 1002)
第3天：跳转商店  →  JWT 带的是 1002  →  商店以为是新用户！
```

**`user_id` 不会因主角色切换而变化**，所以商店的用户表应该用 `sub`（即 `user_id`）做主键。`main_character_id` 和 `name` 只用于显示，每次跳转时商店应更新这两个字段。

### 关于 alt 角色登录

无论用户用哪个 alt 角色登录 SeAT，`auth()->user()` 返回的都是同一个 User 对象：

- `user->id` 相同
- `user->main_character_id` 相同

所以 JWT 的内容不受登录角色影响。

---

## 第三部分：商店验证 JWT

商店收到 `?token=eyJ...` 后，执行以下步骤：

### 步骤 1：拆分 JWT

```
parts = token.split(".")
header = base64url_decode(parts[0])
payload = base64url_decode(parts[1])
signature = parts[2]
```

### 步骤 2：验证签名

```python
expected = base64url(HMAC_SHA256(parts[0] + "." + parts[1], API_TOKEN))
if expected != parts[2]:
    return 401  # 签名无效
```

### 步骤 3：检查过期

```python
if payload["exp"] < current_time():
    return 401  # JWT 已过期
```

### 步骤 4：创建或更新用户

```python
user = db.find_or_create(user_id=payload["sub"])
user.main_character_id = payload["main_character_id"]  # 更新显示信息
user.name = payload["name"]
user.save()
```

### 步骤 5：创建商店 session

创建商店自己的 session（cookie），有效期由商店自定。JWT 的 60 秒限制只约束跳转本身，不限制商店的会话时长。

### 各语言验证示例

**Python**：

```python
import hmac, hashlib, base64, json, time

def base64url_decode(s):
    s += '=' * (4 - len(s) % 4)
    return base64.urlsafe_b64decode(s)

def verify_jwt(token, secret):
    parts = token.split('.')
    if len(parts) != 3:
        return None

    # 验证签名
    signing_input = f"{parts[0]}.{parts[1]}".encode()
    expected = base64.urlsafe_b64encode(
        hmac.new(secret.encode(), signing_input, hashlib.sha256).digest()
    ).rstrip(b'=').decode()

    if not hmac.compare_digest(expected, parts[2]):
        return None  # 签名无效

    payload = json.loads(base64url_decode(parts[1]))

    # 检查过期
    if payload.get('exp', 0) < time.time():
        return None  # 已过期

    return payload
```

**PHP**：

```php
function verifyJwt(string $token, string $secret): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    // 验证签名
    $expected = rtrim(strtr(base64_encode(
        hash_hmac('sha256', "$parts[0].$parts[1]", $secret, true)
    ), '+/', '-_'), '=');

    if (!hash_equals($expected, $parts[2])) return null;

    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

    // 检查过期
    if (($payload['exp'] ?? 0) < time()) return null;

    return $payload;
}
```

**JavaScript (Node.js)**：

```javascript
const crypto = require('crypto');

function verifyJwt(token, secret) {
    const parts = token.split('.');
    if (parts.length !== 3) return null;

    // 验证签名
    const expected = crypto
        .createHmac('sha256', secret)
        .update(`${parts[0]}.${parts[1]}`)
        .digest('base64url');

    if (expected !== parts[2]) return null;

    const payload = JSON.parse(
        Buffer.from(parts[1], 'base64url').toString()
    );

    // 检查过期
    if ((payload.exp || 0) < Math.floor(Date.now() / 1000)) return null;

    return payload;
}
```

---

## 第四部分：PAP 查询 API

商店需要查询用户的 PAP 余额时，调用以下接口。

### 认证方式

所有 API 请求必须携带 API Token：

```
# 方式一：HTTP Header（推荐）
Authorization: Bearer <API_TOKEN>

# 方式二：Query 参数
?token=<API_TOKEN>
```

### 单角色查询

查询指定角色的主角色聚合 PAP 总数。

**请求**：

```
GET https://seat.example.com/api/calendar/paps/{character_id}
Authorization: Bearer <API_TOKEN>
```

`character_id` 可以是主角色或任意 alt 的 ID，结果都会聚合到同一个主角色下。

**成功响应** (200)：

```json
{
    "status": "success",
    "character_id": 2118151113,
    "user_id": 120,
    "total_pap": 3.0,
    "sync_at": "2026-03-29 08:27:04"
}
```

| 字段 | 说明 |
|------|------|
| `character_id` | 主角色 ID（已自动解析） |
| `user_id` | SeAT user_id（与 JWT 中的 `sub` 一致） |
| `total_pap` | 2026 年起所有 alt 的 PAP 合计 |
| `sync_at` | 查询时间 |

**角色未找到** (404)：

```json
{
    "status": "error",
    "message": "Character not found or not linked to a SeAT user."
}
```

### 批量查询

适用于定时任务批量同步所有成员 PAP。单次最多 200 个。

**请求**：

```
GET https://seat.example.com/api/calendar/paps?characters=2118151113,2118151114,2118151115
Authorization: Bearer <API_TOKEN>
```

**成功响应** (200)：

```json
{
    "status": "success",
    "data": [
        {"character_id": 2118151113, "user_id": 120, "total_pap": 3.0},
        {"character_id": 2118151114, "user_id": 121, "total_pap": 5.0}
    ],
    "not_found": [2118151115],
    "sync_at": "2026-03-29 02:00:04"
}
```

### 错误码汇总

| HTTP 状态码 | 含义 |
|-------------|------|
| 200 | 查询成功 |
| 400 | 缺少 `characters` 参数或数量超过 200 |
| 401 | Token 错误或缺失 |
| 404 | 角色未找到（仅单角色查询） |
| 503 | SeAT 未配置 API Token |

### PAP 聚合逻辑

无论传入哪个角色 ID，API 内部都会：

1. 通过 `refresh_tokens` 表找到该角色所属的 SeAT 用户
2. 获取该用户下所有关联角色（主角色 + 全部 alt）
3. 对 `kassie_calendar_paps` 表中这些角色 2026 年起的 PAP 值求和
4. 返回主角色 ID 和聚合后的总数

```
用户有 A（主）、B、C 三个角色
查询 A、B 或 C 中任意一个，返回结果完全相同：
  character_id = A
  total_pap = PAP(A) + PAP(B) + PAP(C)
```

---

## 第五部分：对商店的要求

### 数据库设计

商店用户表建议结构：

```sql
CREATE TABLE users (
    user_id       INT PRIMARY KEY,     -- 来自 JWT sub，SeAT user_id
    character_id  BIGINT,              -- 来自 JWT main_character_id，显示用
    name          VARCHAR(255),        -- 来自 JWT name，显示用
    pap_balance   DECIMAL(10,2),       -- 本地缓存的 PAP 余额
    pap_synced_at DATETIME,            -- 上次同步时间
    created_at    DATETIME,
    updated_at    DATETIME
);
```

**关键：`user_id` 做主键，不要用 `character_id`。**

### 用户登录流程

```
1. 收到 ?token=eyJ...
2. 验证 JWT 签名和过期时间
3. 从 payload 取 sub (user_id)
4. 查找或创建本地用户（以 user_id 为主键）
5. 更新 character_id 和 name（这两个可能因主角色切换而变化）
6. 创建商店 session
7. 重定向到商店首页
```

### PAP 余额同步策略

建议两种策略结合使用：

**实时查询**（用户触发）：

- 用户在商店点"刷新余额"
- 商店调用单角色查询 API
- 更新本地缓存

**定时批量同步**（后台任务）：

- 每小时或每天定时执行
- 收集所有活跃用户的 character_id
- 调用批量查询 API（每次最多 200 个，分批）
- 更新所有用户的本地 PAP 余额

### PAP 消费处理

当用户在商店消费 PAP 时：

- **商店只维护自己的消费记录和余额**
- **不要**调用 SeAT API 去扣减 PAP — SeAT 侧的 PAP 是只增不减的原始记录
- 商店的可用余额 = 从 API 同步的 total_pap - 商店已消费的总额

```
可用余额 = API返回的total_pap - 商店记录的已消费总额
```

### 头像显示

EVE 角色头像可通过 EVE Image Server 获取，无需任何认证：

```
https://images.evetech.net/characters/{character_id}/portrait?size=128
```

`character_id` 使用 JWT 中的 `main_character_id`。

### 安全要求

| 要求 | 说明 |
|------|------|
| HTTPS | 商店必须使用 HTTPS，防止 JWT 在传输中被截获 |
| Token 保密 | API Token 只存在服务端环境变量中，不暴露给前端 |
| 签名验证 | 必须验证 JWT 签名，不能只解码 payload |
| 过期检查 | 必须检查 `exp` 字段，拒绝过期 JWT |
| timing-safe 比较 | 签名比较应使用 constant-time 函数（如 `hmac.compare_digest`），防止时序攻击 |

---

## 第六部分：完整配置步骤

### SeAT 管理员操作

1. 进入 **日历 → 设置**
2. 在 **PAP API 设置** 卡片中点击 **生成 Token**
3. 复制 Token，配置到商店服务器
4. 在 **PAP 商店跳转设置** 卡片中填写商店认证地址（如 `https://shop.example.com/auth`）
5. 保存

### 商店服务器配置

```bash
# .env 或环境变量
SEAT_API_TOKEN=<从 SeAT 复制的 Token>
SEAT_API_URL=https://seat.example.com
```

### 验证

1. 在 SeAT 侧边栏点击 **PAP 商店**
2. 应跳转到商店并自动登录
3. 在商店中查看 PAP 余额，确认与 SeAT 角色页面一致

---

## 附录：时序图

### 首次访问

```
浏览器                    SeAT                     商店
  │  点击"PAP 商店"         │                        │
  │ ──────────────────────> │                        │
  │                         │ 生成 JWT                │
  │  302 shop/auth?token=   │                        │
  │ <────────────────────── │                        │
  │                         │                        │
  │  GET shop/auth?token=   │                        │
  │ ───────────────────────────────────────────────> │
  │                         │        验证JWT → 创建用户 │
  │                         │        创建 session      │
  │  Set-Cookie + 302 /     │                        │
  │ <─────────────────────────────────────────────── │
  │                         │                        │
  │  商店首页                │                        │
  │ ───────────────────────────────────────────────> │
  │                         │                        │
  │                         │  需要 PAP 余额           │
  │                         │  GET /api/calendar/paps │
  │                         │ <───────────────────── │
  │                         │  {total_pap: 3.0}      │
  │                         │ ─────────────────────> │
  │                         │                        │
  │  显示余额: 3.0 PAP      │                        │
  │ <─────────────────────────────────────────────── │
```

### 再次访问（session 有效）

```
浏览器                    SeAT                     商店
  │  点击"PAP 商店"         │                        │
  │ ──────────────────────> │                        │
  │  302 shop/auth?token=   │                        │
  │ <────────────────────── │                        │
  │                         │                        │
  │  GET shop/auth?token=   │                        │
  │ ───────────────────────────────────────────────> │
  │                         │    验证JWT → 更新用户信息 │
  │                         │    session 已存在或刷新   │
  │  302 /                  │                        │
  │ <─────────────────────────────────────────────── │
```
