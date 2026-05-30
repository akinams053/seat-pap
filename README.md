# seat-pap

[![Core Version](https://img.shields.io/badge/SeAT-5.0.x-blue?style=for-the-badge)](https://github.com/eveseat/seat)
[![License](https://img.shields.io/github/license/akinams053/seat-pap?style=for-the-badge)](https://github.com/akinams053/seat-pap/blob/localization/LICENCE)

面向 **SeAT 5.x** 的 Calendar / PAP 插件。

## 功能概览

### Operation 管理
- 创建 / 更新 / 取消 / 重新激活 / 关闭 / 删除 operation
- 基于角色（Role）的 operation 可见性控制
- 用户按角色报名 operation（attending / not attending / maybe）
- operation 标签管理（分类、排序、analytics 分轴、quantifier）

### PAP 采集与统计
- FC 通过 ESI 拉取当前 fleet 成员，一键生成 PAP
- PAP 发放后自动更新舰队 MOTD，通知成员发放结果
- **主角色聚合**：所有 alt 的 PAP 自动归并到 main character
- 阶段 7 统计口径会逐步区分：
  - **出勤 PAP**：普通行动最终认可贡献
  - **消费 PAP**：抽奖 / 商店等消费型扣减
  - **当前可用 PAP**：剩余可继续消费的 PAP
- 角色维度：
  - 当月 / 当年 PAP 汇总卡片
  - 每月参与趋势折线图
  - 周 / 月 / 年排名榜（按主角色聚合）
- 军团维度：
  - 月度参与趋势折线图（可按年份筛选）
  - PAP 类型分布饼图 — 月度（可按年月筛选）
  - PAP 类型分布饼图 — 年度（可按年份筛选）
  - 排名榜（可按年月筛选，支持导出 Excel）

### PAP API
- 提供 REST API 供外部服务（如 PAP 商店）查询主角色聚合后的当前可用 PAP
- 支持单角色查询和批量查询（最多 200 个）
- 支持 `since` 参数指定统计起始日期（默认 2026-01-01）
- Token 认证，无需用户二次登录或 SSO 授权
- 在 **Calendar → Settings** 页面生成和管理 API Token

### PAP 商店跳转
- 侧边栏 **PAP 商店** 入口（需要 `calendar.view` 权限）
- 跳转时携带 JWT 令牌（含用户 ID、主角色信息），商店用 API Token 验证签名
- 管理员在设置页面配置商店地址

### 行动审查（Operation Audit）
- 侧边栏 **行动审查** 入口，列出所有已发放 PAP 的行动
- 列表显示 PAP 发放时间（精确到秒）、FC、单 PAP、人数、总额，列可排序
- 拥有 `calendar.create` 权限的用户可对任意可见行动点击「PAP 审查」查看舰队成员快照（含船型、星系、加入时间）
- 支持对单个成员奖励或扣除 PAP，类型自动继承自本次行动，必须填写理由
- 调整结果实时回写，所有统计页面与外部商店 API 自动同步
- 完整审计轨迹：每次奖惩记录操作人（SeAT 用户主角色）、时间、理由

### MOTD 自定义
- 在设置页面可自定义舰队 MOTD 的各要素颜色
- 固定要素：标题、舰队名称、舰队人数、PAP 值、PAP 类型、发放时间
- 底部签名可自定义内容和颜色
- 设置页面提供实时预览

### 排名显示
- 前三名显示金 / 银 / 铜奖杯图标
- 每行进度条显示相对贡献占比
- 角色页面自动高亮你的主角色排名位置

## 兼容性

| 项目 | 版本 |
|------|------|
| SeAT | 5.x |
| Laravel | 10.x |
| PHP | 8.1 - 8.4 |

目标部署环境：**Ubuntu 22.04**

## 项目文档

更多指南和功能设计文档见 [`docs/README.md`](docs/README.md)：

- [`docs/FC指南.md`](docs/FC指南.md)：FC / 管理人员日常操作说明。
- [`docs/PAP商店对接指南.md`](docs/PAP商店对接指南.md)：PAP 商店 JWT 跳转与 API 对接协议。
- [`docs/PAP超网抽奖功能计划.md`](docs/PAP超网抽奖功能计划.md)：PAP 超网抽奖模块设计草案。
- [`docs/PAP统计口径调整计划.md`](docs/PAP统计口径调整计划.md)：阶段 7 出勤 PAP / 消费 PAP / 当前可用 PAP 统计语义设计。

## 安装

当前开发分支为 `localization`，需要指定版本安装。

Packagist 页面：https://packagist.org/packages/akinams053/seat-pap#dev-localization

在 **SeAT 根目录**（默认 `/var/www/seat`）执行：

```bash
cd /var/www/seat

# 安装插件（需要 sudo 以写入 vendor 目录）
sudo composer require akinams053/seat-pap:dev-localization

# 发布静态资源（CSS/JS）
sudo php artisan vendor:publish --force --provider="Seat\Kassie\Calendar\CalendarServiceProvider"

# 执行数据库迁移
php artisan migrate

# 清除缓存
php artisan view:clear
php artisan route:clear
php artisan cache:clear
```

> **注意**：如果 `composer require` 提示权限问题，请使用 `sudo`。SeAT 目录通常属于 `www-data` 用户。

## 更新

```bash
cd /var/www/seat

# 拉取最新版本
sudo composer update akinams053/seat-pap

# 重新发布静态资源
sudo php artisan vendor:publish --force --provider="Seat\Kassie\Calendar\CalendarServiceProvider"

# 执行新增迁移（如有）
php artisan migrate

# 清除缓存
php artisan view:clear
php artisan route:clear
php artisan cache:clear
```

## 使用说明

### 权限设置

安装后需要在 SeAT 后台为用户角色分配以下权限：

| 权限 | 说明 |
|------|------|
| `calendar.view` | 查看 operation 列表 |
| `calendar.create` | 创建新 operation |
| `calendar.setup` | 管理 tags、MOTD、API Token 等设置 |
| `calendar.update_all` | 编辑他人的 operation |
| `calendar.cancel_all` | 取消 / 关闭他人的 operation |
| `calendar.delete_all` | 删除他人的 operation |
| `character.kassie_calendar_paps` | 查看角色 PAP 页面 |
| `corporation.kassie_calendar_paps` | 查看军团 PAP 页面 |

设置路径：**SeAT 后台 → Access Management → Roles → 选择角色 → Permissions**

### 日常使用流程

1. **创建 Operation**：导航到 Calendar → Operations，点击创建，填写标题、时间、FC、集结星系、标签等
2. **报名**：用户在 operation 详情中选择角色和参与状态
3. **PAP 采集**：operation 进行中，FC 点击 PAP 按钮，系统通过 ESI 拉取 fleet 成员列表并记录
4. **查看统计**：
   - 角色 PAP：角色侧栏 → Paps
   - 军团 PAP：军团侧栏 → Paps

### PAP 使用前提

FC 角色必须在 SeAT 中注册并授权以下 ESI scope：

```
esi-fleets.read_fleet.v1
esi-fleets.write_fleet.v1
```

- `read_fleet`：读取舰队成员列表，用于 PAP 采集
- `write_fleet`：PAP 发放后自动更新舰队 MOTD 通知成员

PAP 采集时，FC 必须是 fleet boss。

### 舰队 MOTD 通知

PAP 发放成功后，系统会自动更新舰队 MOTD，以彩色格式展示：
- 舰队名称（operation 标题）
- 舰队人数
- PAP 值（取 tag 中最大 quantifier）
- PAP 类型（取 tag 的 analytics 字段）
- 发放时间（EVE 时间）
- 可选的底部签名

如果 PAP 发放过程中出现错误，MOTD 会显示红色错误提示。

MOTD 更新失败不会影响 PAP 的正常发放。

MOTD 的颜色和签名内容可在 **Calendar → Settings** 页面自定义。

### PAP API

本插件提供 REST API，允许外部服务（如 PAP 商店）查询主角色聚合后的 PAP 总数。**外部服务只需持有管理员生成的 API Token 即可调用，无需用户二次登录或 SSO 授权。**

#### 配置步骤

1. 导航到 **Calendar → Settings** 页面
2. 在页面底部找到 **PAP API 设置** 卡片
3. 点击 **生成 Token** 按钮
4. 将生成的 Token 配置到外部服务中

> 需要 `calendar.setup` 权限才能管理 API Token。

#### 单角色查询

适用于用户手动刷新个人 PAP 余额等场景。

**请求**：

```
GET https://your-seat-domain/api/calendar/paps/{character_id}
Authorization: Bearer <token>
```

可选参数 `since`（格式 `YYYY-MM-DD`）：只统计该日期之后的 PAP。不传时默认为 `2026-01-01`。

```
GET https://your-seat-domain/api/calendar/paps/{character_id}?since=2026-03-01
```

**响应**：

```json
{
    "status": "success",
    "character_id": 2118151113,
    "user_id": 120,
    "total_pap": 3.0,
    "since": "2026-01-01",
    "sync_at": "2026-03-28 08:27:04"
}
```

- `character_id`：主角色 ID（已自动聚合所有 alt）
- `user_id`：SeAT 用户 ID
- `total_pap`：`since` 日期起的 PAP 总数（主角色 + 所有 alt 合并）
- `since`：实际使用的起始日期
- `sync_at`：查询时间

角色未找到时返回 `404`：

```json
{
    "status": "error",
    "message": "Character not found or not linked to a SeAT user."
}
```

#### 批量查询

适用于定时任务批量同步所有成员 PAP 的场景。单次最多 200 个角色。

**请求**：

```
GET https://your-seat-domain/api/calendar/paps?characters=2118151113,2118151114,2118151115
Authorization: Bearer <token>
```

同样支持 `since` 参数：

```
GET https://your-seat-domain/api/calendar/paps?characters=2118151113,2118151114&since=2026-03-01
```

**响应**：

```json
{
    "status": "success",
    "data": [
        {"character_id": 2118151113, "user_id": 120, "total_pap": 3.0, "since": "2026-01-01"},
        {"character_id": 2118151114, "user_id": 121, "total_pap": 5.0, "since": "2026-01-01"}
    ],
    "not_found": [2118151115],
    "sync_at": "2026-03-28 02:00:04"
}
```

- `data`：查询成功的角色列表
- `not_found`：未找到或未绑定 SeAT 用户的角色 ID 列表

#### 认证方式

支持两种传递 Token 的方式：

```bash
# 方式一：HTTP Header（推荐）
curl -H "Authorization: Bearer YOUR_TOKEN" https://your-seat-domain/api/calendar/paps/2118151113

# 方式二：Query 参数
curl "https://your-seat-domain/api/calendar/paps/2118151113?token=YOUR_TOKEN"
```

#### 错误码

| HTTP 状态码 | 含义 |
|-------------|------|
| 200 | 查询成功 |
| 400 | 请求参数缺失或角色数量超过 200 |
| 401 | Token 错误或缺失 |
| 404 | 角色未找到（仅单角色查询） |
| 503 | 未配置 API Token |

### ESI Scope 配置

本插件需要 FC 角色授权 `esi-fleets.write_fleet.v1` scope 来更新舰队 MOTD。SeAT 默认的 SSO scope 列表中不包含此项，需要手动添加。

**配置步骤**：

1. 在 SeAT 服务器上执行以下命令，将 `write_fleet` scope 加入 SSO 请求列表：

```bash
php artisan tinker --execute="\$s = \Seat\Services\Models\GlobalSetting::where('name', 'sso_scopes')->first(); \$v = json_decode(\$s->value, true); \$v[0]['scopes'][] = 'esi-fleets.write_fleet.v1'; \$s->value = json_encode(\$v); \$s->save(); echo 'done';"
```

2. 清除缓存：

```bash
php artisan cache:clear
```

3. 前往 [EVE 第三方应用管理](https://community.eveonline.com/support/third-party-applications/) 撤销 SeAT 的旧授权

4. 在 SeAT 中重新添加 / 授权 FC 角色，确保 EVE SSO 弹出新的权限确认页面

5. 验证 token 包含 write scope：

```bash
php artisan tinker --execute="\$t = \Seat\Eveapi\Models\RefreshToken::find(YOUR_CHARACTER_ID); foreach(\$t->scopes as \$s) { if(str_contains(\$s, 'fleet')) echo \$s . PHP_EOL; }"
```

应输出：
```
esi-fleets.read_fleet.v1
esi-fleets.write_fleet.v1
```

> **注意**：如果不配置 write scope，PAP 采集仍可正常工作，仅 MOTD 更新会静默失败。

### 主角色聚合

- PAP 统计自动按 SeAT 用户的 **main character** 聚合
- 一个用户的所有 alt 角色产生的 PAP 会合并计入主角色名下
- 角色 PAP 页面的图表展示的是该用户（含所有 alt）的汇总数据
- 军团排名默认展示主角色聚合结果
- API 返回的 `total_pap` 同样是主角色聚合后的结果

### Tag 与 Analytics

Tag 的两个关键字段影响 PAP 统计：

- **Quantifier**：PAP 数值权重（operation 的 PAP 值取所有 tag 中最大的 quantifier）
- **Analytics**：分类轴名称（如 Strategic、PvP、Mining），用于图表中的类型分布统计

## 开发说明

```bash
# 在插件仓库目录
composer install
vendor/bin/rector process
```

当前仓库没有自动化测试套件，验证依赖 SeAT 宿主中的集成测试。

### 建议验证项

- operation 创建 / 更新 / 取消 / 重新激活 / 关闭 / 删除
- attendee 报名
- PAP 拉取
- PAP 发放后舰队 MOTD 是否正确更新
- 角色 PAP 页面（汇总卡片 + 趋势图 + 排名）
- 军团 PAP 页面（趋势图 + 类型分布 + 排名 + Excel 导出）
- 设置页面 MOTD 颜色和签名自定义
- 主角色聚合结果是否正确
- PAP API 单角色查询 / 批量查询 / Token 认证 / 错误码

## 历史说明

本项目 fork 自 [hermesdj/seat-calendar](https://github.com/hermesdj/seat-calendar)，已移除 Discord / Slack / Mail / 外部通知集成功能，重新定位为核心 Calendar / PAP 插件。

## License

GPL-3.0-or-later
