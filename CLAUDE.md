# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 当前目标环境

本仓库正在为 **SeAT 5.x** 宿主环境进行重构，目标部署环境为 **Ubuntu 22.04**，当前已确认宿主环境核心信息如下：

- **Laravel 10.50.x**
- **PHP 8.4.x**
- **MySQL**
- **Redis**（用于 cache / queue）

当前插件**尚未安装**到目标服务器。后续在本仓库中的分析、修改和设计，都应基于“面向现代 SeAT 5 宿主重构”的前提，而不是假设旧版部署方式仍然成立。

当前已确认的目标 SeAT 根目录为：`/var/www/seat`

## 当前重构目标

这个包不再按“通用日历 + 通知 + Discord 集成插件”的方向维护。

当前目标是：**在最小修改原则下，保留核心业务能力，只剥离已经不再需要的通知与外部集成功能。**

### 重构原则

- 坚持**最小修改原则**
- 优先做“只剥离通知功能”的收缩式重构
- 不做与当前目标无关的大规模重写
- 除非某处通知代码已经实质性阻塞核心功能兼容，否则不要顺手扩散式改造其它模块

### 必须保留的功能范围

- operation 生命周期管理
- attendee / 报名 / 注册流程
- PAP 功能
- 小队 / 舰队相关 operation 流程
- character / corporation 统计能力
- summary / aggregation / analytics 相关视图与汇总能力
- tags（仅保留对核心筛选、组织、统计仍有价值的部分）

### PAP 汇总要求

PAP 不仅要保留，还必须支持**按主角色汇总**：

- 以 SeAT 用户的 **main character** 作为汇总键
- 将该用户下所有子角色 / alt 角色的 PAP 数据合并统计
- character / corporation 相关汇总、报表、统计视图在需要汇总时，应优先体现主角色维度的聚合结果

### 应移除或逐步淘汰的功能范围

- Discord 集成
- Slack 集成
- Mail 通知集成
- 本插件对 SeAT notification-group / external integration 的耦合
- Discord OAuth / guild event sync / Discord 专属设置页面与流程

在做设计和代码取舍时，**优先保护 PAP、统计、汇总、operation 核心流程**；所有通知相关代码路径都应视为删除候选。

## 常用命令

### 插件仓库内执行

以下命令在**当前插件仓库根目录**执行：

- `composer install`
- `vendor/bin/rector process`
- `vendor/bin/rector process src/Http/Controllers/OperationController.php`

说明：

- `rector.php` 当前针对 `src/` 生效，并启用了 PHP 8.2 + Laravel 10 的现代化规则集。
- 虽然 Rector 配置目前写的是 PHP 8.2 目标，但实际宿主运行环境是 **PHP 8.4**，所以所有改动都要额外关注 PHP 8.4 兼容性。

### 宿主 SeAT 环境中执行

以下命令在 **SeAT 根目录** 执行（当前确认路径：`/var/www/seat`）：

- `php artisan --version`
- `php artisan about`
- `php artisan route:list --path=calendar`
- `php artisan route:list | egrep 'paps|calendar'`
- `php artisan migrate:status | egrep 'calendar|kassie|discord'`
- `composer show | egrep 'eveseat/web|eveseat/services|eveseat/notifications|eveseat/eveapi'`

当需要验证插件与 SeAT 的耦合点时，应优先使用宿主环境命令，而不是只根据本仓库代码做推断。

### 后续部署说明

该插件最终仍应安装到 SeAT 根目录中，**最终安装与发布方式以 Packagist 为目标**。

也就是说，后续重构不仅要考虑代码兼容性，还要考虑：

- `composer.json` 元数据是否适合发布
- 包结构是否适合通过 Packagist 安装
- 安装说明是否能够落到标准 `composer require <package>` 流程
- 不应把最终方案建立在长期依赖 path repository 或手工拷贝源码之上

当前 SeAT 宿主 `composer.json` 已确认：

- `minimum-stability: dev`
- `prefer-stable: true`
- 目前**没有**自定义 `repositories` 配置

因此后续部署设计应尽量朝以下方向收敛：

- 发布到 Packagist
- 在 SeAT 宿主中通过标准 Composer 依赖方式安装
- 尽量减少宿主侧额外自定义配置

### 测试现状

当前仓库**没有现成的 PHPUnit / Pest 测试套件，也没有 `phpunit.xml`**。

不要假设本仓库已经具备完整自动化测试能力。后续如果补测试，通常需要二选一：

- 在本插件仓库中从零补齐 package-local tests
- 在真实 SeAT 宿主中做集成验证

## 高层架构

## 启动入口与包接入点

`src/CalendarServiceProvider.php` 是整个包的主入口。它目前负责注册或加载：

- commands
- routes
- views
- translations
- migrations
- assets publication
- `OperationObserver`
- package permissions
- notification config
- Discord Socialite driver
- 定时 reminder command

如果要移除通知/Discord 相关能力，这个文件通常是**第一优先级检查点**，因为很多历史遗留的启动逻辑都集中在这里。

## 当前代码的两大领域

当前代码实际上混合了两类职责。

### 1. 重构后应保留的核心业务域

- operation 的增删改查与状态流转
- attendee 的报名与参与状态
- PAP 的采集、展示、统计、汇总
- character / corporation 维度的 PAP 与统计视图
- 为核心业务服务的 tag 组织能力
- 基于角色的 operation 可见性控制

### 2. 重构后应删除或大幅收缩的集成域

- notification dispatching
- Discord event synchronization
- Slack / Discord / Mail 通知 handler
- 专门服务于外部集成的 settings 页面
- 基于 SeAT notification infrastructure 的 integration filtering

## 路由结构

所有插件路由都定义在：`src/Http/routes.php`

重点路由组：

- `/calendar/...`：主日历 UI、AJAX 数据、settings、tags、lookups
- `/character/{character}/paps`：角色 PAP 视图
- `/corporation/{corporation}/paps`：军团 PAP 页面与 JSON 统计接口

重构时应尽量保持 **PAP 路由** 与 **核心 calendar 路由** 的稳定性。与 settings 相关的路由，在移除通知/集成能力后，大概率会显著收缩。

## 前端/UI 形态

本项目不是 SPA，而是典型的服务端渲染结构，主要由以下技术组成：

- **Blade**
- **jQuery**
- **Bootstrap modals**
- **DataTables**

关键入口：

- `src/resources/views/operation/index.blade.php`
- `src/resources/assets/js/calendar.js`
- `src/resources/assets/js/settings.js`
- `src/Http/Controllers/AjaxController.php`

前端相关修改通常需要联动检查：

1. Blade 模板 / partial
2. `resources/assets/js` 中的 jQuery 行为
3. route / controller 输出的 JSON 结构
4. SeAT 宿主中发布后的静态资源行为

除非明确要求，不建议直接做 SPA 化或框架级重写，应优先做渐进式整理。

## operation 核心后端流程

`src/Http/Controllers/OperationController.php` 是 operation 生命周期相关写路径的核心控制器。

当前核心流程大致是：

1. 校验请求输入
2. 创建或更新 `Operation`
3. attach / sync 相关 tags
4. 保存模型
5. 触发副作用

历史上，第 5 步通常包括：

- notifications
- Discord sync

本次重构中，应优先保留前 1～4 步；第 5 步只保留**真正属于核心业务**的部分，移除所有集成性质的副作用。

这个控制器也是以下功能的关键依赖点：

- operation 创建 / 更新
- close / cancel / activate 等状态切换
- PAP 相关 operation 明细交互
- attendee / registration UI 的行为预期

## 数据模型概览

核心模型包括：

- `Operation`：日历 operation 的核心聚合根
- `Attendee`：每个用户 / 角色的报名与参与状态
- `Tag`：分类能力；历史上也曾用于 integration filtering
- `Pap`：PAP 数据与统计来源

核心关系包括：

- `Operation` belongs to SeAT `User`
- `Operation` has many `Attendee`
- `Operation` belongs to many `Tag`
- `Pap` 支撑 character / corporation 维度的统计与报表

### 一个必须注意的兼容性细节

`Operation` 当前仍通过 `description` / `description_new` 做旧字段与新字段的兼容桥接。

清理代码时**不要想当然地直接简化掉这层兼容**，必须先确认历史数据和现有视图/表单是否仍依赖它。

## PAP 与统计链路

PAP / 报表 / 汇总是本次重构必须优先保护的主线之一。

相关代码主要集中在：

- `src/Http/Controllers/CharacterController.php`
- `src/Http/Controllers/CorporationController.php`
- `src/Http/Controllers/LookupController.php`
- `src/Http/Controllers/OperationController.php`
- `src/Models/Pap.php`
- `src/resources/views/character/`
- `src/resources/views/corporation/`
- `src/database/migrations/` 下与 PAP / analytics 相关的 migration

特别注意：

`CorporationController` 中存在较重的 query builder / SQL 聚合逻辑。修改这部分时，应优先保证**统计结果正确性**，不要为了“代码更漂亮”而轻易改变分组或聚合行为。

另外，PAP 汇总在本项目中应默认优先考虑**主角色聚合**：

- 如涉及用户维度汇总，应优先通过 SeAT 用户与 main character 的关系归并 alt 数据
- 如果某个页面既支持单角色视图又支持汇总视图，应明确区分“角色明细”和“主角色汇总”两种语义
- 调整查询时，要验证主角色汇总后的 totals 没有因为 join / groupBy 变化而被放大或丢失

## 可视为删除候选的通知与集成代码

以下区域不属于目标最终形态，应优先视为**删除 / 简化候选**：

- `src/Discord/`
- `src/Notifications/Discord/`
- `src/Notifications/Slack/`
- `src/Notifications/` 中偏通知集成的部分
- `src/Http/Controllers/SettingController.php` 中与 Discord / integration / notification 相关的逻辑
- `src/Config/` 中与 Discord / notification 相关的配置
- 如 `calendar:discord:sync` 这类 Discord 专属命令
- observer / controller 中仅用于外部通知派发的逻辑
- `src/resources/views/setting/` 下所有偏外部集成的 Blade 片段

但要注意：

有些历史代码虽然来自通知体系，可能间接被核心 UI / tag / operation 流程复用。删除前必须确认它**不被 PAP / operation 核心链路依赖**。

## migration 与历史包袱

`src/database/migrations/` 反映了这个项目较长时间跨度内的演进历史，其中有一部分 migration 明显是为 Slack / Discord / integration 功能服务的。

后续判断取舍时，应遵循：

- 保留 operation / attendee / tag / PAP / analytics / reporting 必需的 schema
- 将通知 / 外部集成相关 schema 视为弃用候选
- 除非已经明确制定迁移策略，否则优先采用**前向安全的清理方式**，避免贸然做破坏性 schema 变更

## 已确认的宿主环境事实

来自目标 SeAT 宿主的已确认事实：

- Laravel：`10.50.2`
- PHP：`8.4.18`
- 已安装 SeAT 包：`eveseat/web`、`eveseat/services`、`eveseat/eveapi`、`eveseat/notifications`
- `.env` 当前显示：`APP_ENV=local`、`DB_CONNECTION=mysql`、`CACHE_DRIVER=redis`、`QUEUE_CONNECTION=redis`、`SESSION_DRIVER=file`
- 宿主使用标准 Laravel 10 项目结构，没有单独的 `/routes` 目录

这些事实在后续做兼容性判断、部署建议、架构裁剪时，应作为默认前提。

## 本仓库的工作约定

- 把本项目视为 **SeAT 扩展包**，不是独立应用。
- 所有重构优先面向 **SeAT 5.x + Laravel 10 + PHP 8.4** 兼容。
- 坚持**最小修改原则**：优先只剥离通知功能，不做无关的大范围重写。
- 优先删除外部集成代码，而不是继续迁移 Discord / Slack / Mail 行为。
- 最终安装与发布方式以 **Packagist** 为目标，部署建议优先围绕标准 Composer 安装流程。
- PAP / 报表 / 汇总正确性优先于表面上的“代码整洁度”。
- PAP 汇总相关改动默认优先校验“按主角色聚合所有子角色”的结果是否正确。
- 凡是会影响 operation 生命周期共享逻辑的改动，都要额外警惕对 PAP / 统计链路的连带影响。
- 在给出部署建议时，明确区分“插件仓库命令”和“宿主 SeAT 命令”。
- 后续新增文档应**优先使用中文**；必要时可保留英文技术名词、命令、包名、类名。
- 代码中仅在确有必要时添加**简洁中文注释**，避免无意义注释噪音。
