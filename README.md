# seat-calendar

[![Latest Stable Version](https://img.shields.io/packagist/v/hermesdj/seat-calendar.svg?style=for-the-badge)](https://packagist.org/packages/hermesdj/seat-calendar)
[![Downloads](https://img.shields.io/packagist/dt/hermesdj/seat-calendar?style=for-the-badge)](https://packagist.org/packages/hermesdj/seat-calendar)
[![Core Version](https://img.shields.io/badge/SeAT-5.0.x-blue?style=for-the-badge)](https://github.com/eveseat/seat)
[![License](https://img.shields.io/github/license/hermesdj/seat-calendar?style=for-the-badge)](https://github.com/hermesdj/seat-calendar/blob/master/LICENCE)

一个面向 **SeAT 5.x** 的 Calendar / PAP 插件。

## 当前定位

本项目当前以 **SeAT 5.x / Laravel 10 / PHP 8.4** 为目标环境进行收敛式重构。

当前版本的目标是：

- 保留 operation 生命周期管理
- 保留 attendee / 报名流程
- 保留 PAP 采集与展示
- 保留 character / corporation 统计与汇总
- 保留 tag 对筛选、量化和 analytics 的支撑
- 移除 Discord / Slack / Mail / 外部通知集成功能

也就是说，这个包现在更接近一个**核心日历与 PAP 插件**，而不是外部通知集成插件。

## 功能概览

- 创建 / 更新 / 取消 / 重新激活 / 关闭 / 删除 operation
- operation 标签管理
- 用户按角色报名 operation
- FC 拉取 fleet 成员并生成 PAP
- character 维度 PAP 页面
- corporation 维度 PAP 统计与汇总
- analytics 分类统计
- 基于主角色的 PAP 汇总能力

## 兼容性

| 项目 | 版本 |
|------|------|
| SeAT | 5.x |
| Laravel | 10.x |
| PHP | 8.1 - 8.4 |

目标部署环境当前以 **Ubuntu 22.04** 为主。

## 安装

请在 **SeAT 根目录** 中执行：

```bash
composer require hermesdj/seat-calendar
php artisan vendor:publish --force
php artisan migrate
php artisan db:seed --class=Seat\Kassie\Calendar\database\seeds\CalendarTagsSeeder
```

说明：

- 当前安装方式以 **Packagist** 为目标与默认路径。
- 本版本不再要求配置 Discord / Slack / Mail 通知能力。
- 历史 seed 与 migration 仍会保留一部分旧结构，以保证升级链兼容性，但核心运行不再依赖外部通知配置。

## 本次改动说明

本次重构遵循**最小修改原则**，重点不是重写整个插件，而是把仓库收敛为更适合 SeAT 5.x 使用的核心 Calendar / PAP 插件。

### 本次已经完成的调整

- 移除了 operation 生命周期中的通知副作用调用
- 停用了 Discord / Slack / Mail / 外部通知相关的注册入口
- 移除了 settings 页面中的通知与 Discord 配置入口
- 移除了 tag 与外部 notification integrations 的绑定与管理界面
- 收缩了 `composer.json` 依赖，去掉通知与 Discord 相关包依赖
- 保留了 operation、attendee、PAP、analytics、汇总相关主流程

### 本次刻意保留但停用的历史内容

为了降低回归风险，第一轮没有直接物理删除所有历史文件，而是优先做“断注册、断入口、断引用”。因此仓库中仍可能保留以下历史代码文件：

- `src/Notifications/*`
- `src/Discord/*`
- `src/Observers/OperationObserver.php`
- 部分旧 settings 视图片段
- 与通知相关的历史 migrations / seeds

这些内容当前的定位是：**保留升级链与仓库历史兼容性，但不再作为主流程的一部分使用。**

## 升级影响

如果你是从旧版本迁移：

- 旧版 Discord / Slack / Mail / 通知组相关配置将不再生效于当前主流程
- operation 的创建、更新、关闭、取消、激活仍然保留，但不再派发外部通知
- tag 仍保留在核心流程中，但仅用于：
  - operation 分类
  - PAP quantifier
  - analytics 统计
- 与 notification integrations 的 tag 绑定关系不再参与当前版本的核心运行逻辑

如果你的历史环境中仍保留这些旧配置或旧数据结构，通常不会阻塞当前版本的核心功能；但它们也不再是必需配置项。

## 后续计划

当前阶段的目标是先完成“核心功能稳定运行 + 通知链路剥离”。

后续如继续收敛，优先级应为：

1. 在真实 SeAT 5.x 宿主中验证 operation / attendee / PAP / 统计 / 汇总
2. 验证主角色汇总是否符合预期
3. 评估是否需要在稳定后物理删除历史通知文件
4. 持续优化 Packagist 安装体验与发布说明

## PAP 说明

从 1.3.2 起，项目已实现 PAP 机制。

如需使用 PAP 相关功能，FC 角色需要具备：

- `esi-fleets.read_fleet.v1`

PAP 统计中，汇总视图应支持：

- 以 SeAT 用户的 **main character** 为汇总键
- 将该用户下所有 alt / 子角色的 PAP 数据合并统计

## 开发说明

### 本仓库内可用命令

```bash
composer install
vendor/bin/rector process
vendor/bin/rector process src/Http/Controllers/OperationController.php
```

### 当前测试现状

当前仓库没有现成的 PHPUnit / Pest 测试套件，也没有 `phpunit.xml`。

因此验证主要依赖：

1. 代码级检查
2. SeAT 宿主中的集成验证

### 建议的宿主验证项

在 SeAT 根目录中至少验证以下流程：

- operation 创建 / 更新 / 取消 / 重新激活 / 关闭 / 删除
- attendee 报名
- PAP 拉取
- character PAP 页面
- corporation PAP 统计页面
- grouped 汇总下主角色聚合是否正确
- tag 的 quantifier / analytics 是否仍正确影响统计结果

## 维护原则

当前维护策略是：

- 坚持**最小修改原则**
- 优先保证核心业务稳定
- 不再扩展外部通知集成
- 以 Packagist 安装体验为准整理包结构与说明文档

## 支持

如需反馈或协作，建议直接基于当前仓库 issue / PR 流程继续维护。
