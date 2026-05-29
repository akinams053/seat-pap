# PAP 超网抽奖 — 交接说明

> 用途：供下一位接手人快速了解当前分支的实现状态、测试进度、测试服进入方式与下一步动作。

---

## 1. 当前分支与总体状态

- **当前工作分支**：`docs/pap-hypernet-lottery-plan`
- **当前目标**：完成 PAP 超网抽奖第一版（阶段 1–6），并在测试服完成宿主验证。
- **当前总体结论**：
  - 抽奖主流程代码（创建 / 购买 / 开奖 / 退款 / 审查友好化）已经落地。
  - 结构类验证已在测试服实跑通过。
  - 两个阻塞性建表/写库 bug 已修复并推送。
  - 额外新增了「提前开奖」能力（凑不满人时可手动开）。
  - 购买 / 售满开奖 / 提前开奖 / 退款 / 审查 UI 仍需继续按验证清单逐条实测。

---

## 2. 近期关键提交

按时间顺序：

- `e9e3c29` — `docs: 添加 PAP 超网抽奖宿主验证清单`
- `f5f70dc` — `fix: 抽奖创建/购买补齐 calendar_tags / paps 的 NOT NULL 无默认列`
  - 修复 1：`Lottery::reservedTag()` 补 `bg_color` / `text_color`
  - 修复 2：`LotteryController::purchase()` 里的 `Pap::firstOrCreate(...)` 补 `ship_type_id = 0`
- `b04dfbe` — `feat: 抽奖支持提前开奖（凑不满人时 FC 手动开）`
  - `open` 状态且已有至少 1 个已售节点时允许 FC / 管理员提前开奖
  - 提前开奖只从已售节点抽取
  - 未售节点在开奖时写 `voided_at`
  - `draw_log.draw_mode = early`
  - 详情页增加「提前开奖」按钮与独立确认文案
  - 计划文档 / 验证清单已同步更新

---

## 3. 测试服当前确认状态

### 3.1 宿主版本事实

- Laravel：`10.50.2`
- PHP：`8.4.x`
- 数据库：MySQL
- Redis：已使用
- Web URL：`http://ylxh.de`

### 3.2 已确认部署版本

测试服上已确认 `seat-pap` 版本为：

- `docs/pap-hypernet-lottery-plan`
- commit：`b04dfbe`

### 3.3 已实跑通过的验证项

已通过 SSH + SQL 在宿主上确认：

- `kassie_calendar_paps.value` = `decimal(8,2)`
- `kassie_calendar_pap_adjustments.value` = `decimal(8,2)`
- `calendar_tags.analytics` enum 已含 `lottery`
- 三张抽奖表已存在：
  - `kassie_calendar_lotteries`
  - `kassie_calendar_lottery_prizes`
  - `kassie_calendar_lottery_nodes`
- 关键索引存在：
  - `lotteries.operation_id` unique
  - `lotteries.status` index
  - `lotteries.created_by_character_id` index
  - `lottery_nodes(lottery_id, node_number)` unique

### 3.4 已确认修复生效的 bug

#### bug 1：创建抽奖时报错 `bg_color doesn't have a default value`

根因：
- `calendar_tags.bg_color` / `text_color` 为 `NOT NULL` 无默认值
- 抽奖保留 tag 创建时漏填颜色列

现状：
- 已修复并推送
- 保留 tag 会创建为：
  - `name = PAP 抽奖 / Lottery`
  - `bg_color = #d4af37`
  - `text_color = #ffffff`
  - `quantifier = 0`
  - `analytics = lottery`

#### bug 2：购买节点前潜在报错 `ship_type_id doesn't have a default value`

根因：
- `kassie_calendar_paps.ship_type_id` 为 `NOT NULL` 无默认值
- 抽奖购买时首次为成员创建 PAP 行，原实现未补该字段

现状：
- 已修复并推送
- 抽奖 PAP 首次创建时固定写：`ship_type_id = 0`
- 语义：抽奖不是实际舰队成员快照，无真实船型；审查弹窗显示 `—` 即可

---

## 4. 当前行为（接手人必须知道）

### 4.1 开奖按钮为什么有时看不到

这不是 bug，而是当前设计：

- **正常开奖**：只有 `sold_out`（节点全部售完）后才显示「开奖」按钮
- **提前开奖**：在 `open` 状态下，**只要已有至少 1 个已售节点**，就会对 FC / 管理员显示「提前开奖」按钮
- **一个节点都没卖出**：不显示开奖类按钮，只能继续购买或取消退款

### 4.2 提前开奖的语义

提前开奖用于“凑不满人”的运营场景：

- 触发条件：`status = open` 且 `sold_count > 0`
- 候选池：只从已售节点中抽
- 未售节点：开奖时写 `voided_at`，作废，不参与抽取
- `draw_log.draw_mode = early`
- 若奖品数 > 已购人数且不允许重复中奖，多出的奖品可能无人中奖（winner 为 `NULL`）

### 4.3 当前仍未做的事

- 军团 PAP 统计 / 导出语义调整（阶段 7，仍延后）
- 自动开奖
- 到期自动退款
- 手动选号

---

## 5. 测试服连接方式（不落密钥）

本仓库不保存测试服密钥与明文凭据；当前工作区使用兄弟仓库 `seat-fitting` 的 SSH 辅助脚本。

### 5.1 SSH 辅助脚本

在本机执行：

```bash
cd ../seat-fitting
bash scripts/ssh-seat -t test 'your command here'
```

说明：
- 凭据文件：`../seat-fitting/.creds.test`
- 私钥路径也由该文件第 4 行指定
- **不要**把实际 IP、用户名、私钥内容再写进本仓库文档或提交到 git

### 5.2 宿主命令约定

在宿主中跑 artisan 时，优先用：

```bash
sudo -u www-data php artisan ...
```

不要长期直接 root 跑 artisan / tinker，避免权限副作用。

---

## 6. 如何把最新代码部署到测试服

本包通过 Packagist 安装 / 更新。

在 **SeAT 根目录**（宿主）执行：

```bash
cd /var/www/seat
sudo composer update akinams053/seat-pap --no-cache
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan route:clear
sudo systemctl reload php8.4-fpm   # 若服务名不同，按宿主实际情况替换
```

如果本次改动包含：
- **migration**：额外跑 `php artisan migrate`
- **静态资源**：额外跑 `vendor:publish --force --provider="Seat\Kassie\Calendar\CalendarServiceProvider"`

本次 `b04dfbe` 提前开奖改动只涉及 PHP / Blade / lang，**不需要**新增 migration，也**不需要** publish assets。

---

## 7. 下一位接手人的推荐动作

按优先级建议：

1. 打开 `docs/PAP超网抽奖宿主验证清单.md`
2. 先从 **阶段 2 / 3** 继续：
   - 创建一条小抽奖
   - 买 1～2 个节点
   - 核对 `pap_adjustments` / `paps.value` / `lottery_nodes`
3. 再测 **阶段 4**：
   - 售满开奖（`draw_mode = sold_out`）
   - 提前开奖（`draw_mode = early`、未售节点 `voided_at`）
4. 然后测 **阶段 5 / 6**：
   - 取消并退款
   - 审查页徽标 / 单 PAP 展示 / 跳转链接 / adjustments 流水
5. 若测试中出现 SQL / 状态不一致，优先回收：
   - `lotteries`
   - `lottery_nodes`
   - `lottery_prizes`
   - `kassie_calendar_paps`
   - `kassie_calendar_pap_adjustments`
   - `storage/logs/laravel.log`

---

## 8. 建议的最小手工验证用例

### 用例 A：售满开奖

- 节点数：2
- 单价：1
- 每人上限：2
- 奖品：2 个
- 允许重复中奖：勾选
- 单人买满 2 个 → 应进 `sold_out` → 出现「开奖」按钮 → 开完 `draw_mode = sold_out`

### 用例 B：提前开奖

- 节点数：3
- 单价：1
- 每人上限：3
- 奖品：2 个
- 允许重复中奖：勾选
- 单人只买 2 个 → 保持 `open` → 应出现「提前开奖」按钮 → 开完 `draw_mode = early`，剩余 1 个未售节点 `voided_at` 非空

### 用例 C：取消退款

- 节点数：3
- 买 1～2 个后不开奖
- 点「取消并退款」
- 应 `status = cancelled`，并新增正数退款调整，`paps.value` 净额回到 0

---

## 9. 相关文档

- `docs/PAP超网抽奖功能计划.md`
- `docs/PAP超网抽奖宿主验证清单.md`
- `README.md`（Packagist 安装 / 更新方式）

交接时优先读本文件 + 验证清单，其次再回到计划文档看设计原意。
