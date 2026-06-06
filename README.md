# seat-pap

[![Core Version](https://img.shields.io/badge/SeAT-5.0.x-blue?style=for-the-badge)](https://github.com/eveseat/seat)
[![License](https://img.shields.io/github/license/akinams053/seat-pap?style=for-the-badge)](https://github.com/akinams053/seat-pap/blob/main/LICENCE)

面向 **SeAT 5.x** 的 Calendar / PAP 插件：operation 生命周期、报名、PAP 采集与统计、行动审查，以及 PAP 商店 / 超网抽奖外链对接。

## 功能概览

**Operation 与报名**
- 创建 / 更新 / 取消 / 重新激活 / 关闭 / 删除 operation，基于角色的可见性
- 用户按角色报名（attending / maybe / not attending）
- FC 通过 ESI 拉取 fleet 成员一键生成 PAP，并自动更新舰队 MOTD

**PAP 统计（主角色聚合）**
- 所有 alt 的 PAP 自动归并到 main character
- 三口径：**出勤 − 消费 = 当前可用**；全局 PAP 起始日（月初对齐，之前数据保留但不计入）
- 角色页：可用 PAP 卡片、出勤 / 消费双线趋势、荣誉榜
- 军团页：出勤趋势、类型分布、消费汇总、排名（Excel 导出含累计可用余额）

**行动审查**
- 列出出勤行动与抽奖结算行动（商店消费锚不混入）
- `calendar.create` 可对成员事后奖励 / 扣除 PAP，实时回写统计与 API，完整审计轨迹

**外部对接**
- PAP REST API：主角色聚合的可用 PAP 查询（单个 / 批量），Token 认证，无需用户二次登录
- 侧边栏 **PAP 商店** / **超网抽奖** 外链跳转，携带 JWT 鉴权；抽奖开奖后调用 `settle` 结算为可审查 operation
- 详见 [`docs/06-API使用说明.md`](docs/06-API使用说明.md) 与 [`docs/08-抽奖服务器对接指南.md`](docs/08-抽奖服务器对接指南.md)

**MOTD 自定义**：设置页配置舰队 MOTD 各要素颜色与签名，实时预览。

## 兼容性

| SeAT | Laravel | PHP | 部署环境 |
|---|---|---|---|
| 5.x | 10.x | 8.1 – 8.4 | Ubuntu 22.04 |

## 版本与分支

本插件为**单一产品向前演进**，发布用 **tag**（语义化版本）。安装 / 升级 / 回退都以 tag 为准，逐版变化见 [`docs/版本历史.md`](docs/版本历史.md)。

| 版本（tag） | 内容 |
|---|---|
| `2.0.0` | **当前最新**：抽奖外移；开奖后 `settle` 成可审查 operation；三口径统计 / 主角色聚合 / 银行化 API / 限流 300/min / JWT 120s。 |
| `1.0.0` | PAP 商店版（抽奖外移前）：operation / PAP 采集 / 行动审查 / 角色·军团统计 / 商店 API / MOTD。 |

分支：`main`（发布主线，默认）｜`pap-bank`（= 2.0 快照）｜`shop`（1.0 历史基线）｜`dev-lottery`（内置抽奖中间基线，已并入 2.0）。

> Packagist：https://packagist.org/packages/akinams053/seat-pap

## 安装 / 更新 / 回退

在 **SeAT 根目录**（默认 `/var/www/seat`）执行，安装 / 切换都用目标 tag：

```bash
cd /var/www/seat

# 安装 / 升级 / 回退：把 2.0.0 换成目标 tag（如回退用 1.0.0）
sudo composer require akinams053/seat-pap:2.0.0 --no-cache

# 发布静态资源、迁移、清缓存（建议以 www-data 执行 artisan）
sudo -u www-data php artisan vendor:publish --force --provider="Seat\Kassie\Calendar\CalendarServiceProvider"
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan cache:clear
```

> 生产环境的可回退部署流程（整站 tar + 整库快照）见 [`docs/07-生产部署计划.md`](docs/07-生产部署计划.md)。

## 权限

在 **SeAT 后台 → Access Management → Roles → 角色 → Permissions** 分配：

| 权限 | 说明 |
|------|------|
| `calendar.view` | 查看 operation 列表、商店 / 抽奖入口 |
| `calendar.create` | 创建 operation、行动审查奖惩 |
| `calendar.setup` | 管理 tags、MOTD、API Token 等设置 |
| `calendar.update_all` / `cancel_all` / `delete_all` | 管理他人的 operation |
| `character.kassie_calendar_paps` / `corporation.kassie_calendar_paps` | 查看角色 / 军团 PAP 页 |

## 使用前提（ESI scope）

FC 角色需在 SeAT 授权两个 scope：`esi-fleets.read_fleet.v1`（PAP 采集）与 `esi-fleets.write_fleet.v1`（更新 MOTD）。PAP 采集时 FC 必须是 fleet boss。

`write_fleet` 不在 SeAT 默认 SSO scope 列表，需手动加入后重新授权 FC 角色：

```bash
# 将 write_fleet 加入 SSO 请求列表
php artisan tinker --execute="\$s = \Seat\Services\Models\GlobalSetting::where('name', 'sso_scopes')->first(); \$v = json_decode(\$s->value, true); \$v[0]['scopes'][] = 'esi-fleets.write_fleet.v1'; \$s->value = json_encode(\$v); \$s->save(); echo 'done';"
php artisan cache:clear
```

> 不配置 write scope 时 PAP 采集仍正常，仅 MOTD 更新会静默失败。

## 文档

完整功能、统计口径、API 与部署文档见 [`docs/README.md`](docs/README.md)：

- [`01 · 项目说明`](docs/01-项目说明.md)：功能、FC 操作、PAP 统计、行动审查、API 概要
- [`02 · 整体计划`](docs/02-整体计划.md)：三口径统计、主角色聚合、历史内置抽奖背景
- [`06 · API 使用说明`](docs/06-API使用说明.md)：余额查询 / settle / debit / refund / JWT
- [`07 · 生产部署计划`](docs/07-生产部署计划.md)：可回退的生产部署方案
- [`08 · 抽奖服务器对接指南`](docs/08-抽奖服务器对接指南.md)：外部抽奖服务实现指南
- [`版本历史`](docs/版本历史.md)：版本时间线

## 开发

```bash
composer install
vendor/bin/rector process
```

仓库无自动化测试套件，验证依赖 SeAT 宿主中的集成测试。

## 历史与许可

本项目 fork 自 [hermesdj/seat-calendar](https://github.com/hermesdj/seat-calendar)，已移除 Discord / Slack / Mail / 外部通知集成，重新定位为核心 Calendar / PAP 插件。

License：GPL-3.0-or-later
