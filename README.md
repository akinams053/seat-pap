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
- **主角色聚合**：所有 alt 的 PAP 自动归并到 main character
- 角色维度：每月参与趋势、舰船类型分布、全服排名
- 军团维度：
  - 月度参与趋势折线图
  - PAP 类型分布饼图（按 tag analytics）
  - 年度人员柱状图（含帕累托曲线）
  - 月度堆叠柱状图（按类型 x 人员）
  - 周 / 月 / 年排名榜（Top 15，奖杯 + 进度条）

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

## 安装

在 **SeAT 根目录**（默认 `/var/www/seat`）执行：

```bash
composer require akinams053/seat-pap
php artisan vendor:publish --force --provider="Seat\Kassie\Calendar\CalendarServiceProvider"
php artisan migrate
```

## 使用说明

### 权限设置

安装后需要在 SeAT 后台为用户角色分配以下权限：

| 权限 | 说明 |
|------|------|
| `calendar.view` | 查看 operation 列表 |
| `calendar.create` | 创建新 operation |
| `calendar.setup` | 管理 tags 等设置 |
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
```

PAP 采集时，FC 必须是 fleet boss。

### 主角色聚合

- PAP 统计自动按 SeAT 用户的 **main character** 聚合
- 一个用户的所有 alt 角色产生的 PAP 会合并计入主角色名下
- 角色 PAP 页面的图表展示的是该用户（含所有 alt）的汇总数据
- 军团排名默认展示主角色聚合结果
- 详细统计图表可通过「按主角色分组」勾选框切换

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
- 角色 PAP 页面（图表 + 排名）
- 军团 PAP 页面（趋势图 + 类型分布 + 详细统计 + 排名）
- 主角色聚合结果是否正确

## 历史说明

本项目 fork 自 [hermesdj/seat-calendar](https://github.com/hermesdj/seat-calendar)，已移除 Discord / Slack / Mail / 外部通知集成功能，重新定位为核心 Calendar / PAP 插件。

## License

GPL-3.0-or-later
