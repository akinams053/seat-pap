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
  - 已新增 **审查页整行动 PAP 清零** 与 **抽奖详情 5 秒短轮询**。
  - 已在测试服完成一轮 **30 角色并发购买模拟**，结果正确且测试数据已清理。
  - 阶段 2–5（创建、购买扣费、售满开奖、提前开奖、取消退款）已由用户反馈完成验证。
  - 阶段 6 中，旧普通行动清零后曾触发个人 PAP 页排名进度条除零，已通过 `c7655e8` 修复并在测试服验证恢复。
  - 阶段 6 已完成一轮只读/控制器回归复核：审查 JSON、抽奖成员明细、普通清零结果、未终态抽奖清零拒绝、零值排行榜局部视图均通过。
  - 阶段 6 浏览器 UI 目视确认已由用户反馈无误，阶段 1–6 暂无已知阻塞项。

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
- `871b17c` — `feat: 增加审查清零与抽奖短轮询`
  - `/calendar/audit` 成员明细弹窗新增「整行动 PAP 清零」按钮
  - 通过追加反向 `PapAdjustment` 把当前 action 的最终 PAP 冲正为 0，不删除历史记录
  - 未终态 lottery action（`open` / `sold_out`）禁止执行整行动清零
  - 抽奖详情页新增 `lottery.snapshot` 轻量接口与 5 秒短轮询
  - 页面在 `open` / `sold_out` 状态下会自动检测变化并整页刷新；后台标签页会跳过本轮请求
- `c7655e8` — `fix: 防止 PAP 排名进度条除零`
  - 旧普通行动执行整行动 PAP 清零后，个人 PAP 页可能出现某个排行榜最大值为 `0.00`
  - 排名表进度条原先按 `当前 PAP / 最大 PAP * 100` 计算，PHP 8.4 下触发 `DivisionByZeroError`
  - 修复后最大值 `<= 0` 时进度条宽度返回 `0`，正数时才做除法
  - 测试服已通过 Composer 更新到该提交，并由用户确认页面恢复

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
- commit：`c7655e8`

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

### 3.4 已新增并已部署的行为

- 审查页成员明细弹窗已可执行 **整行动 PAP 清零**：
  - 入口在 `/calendar/audit`
  - 通过追加反向 `PapAdjustment` 清零，不删除历史 adjustments
  - 未终态 lottery action 会被后端拒绝
- 抽奖详情页已启用 **5 秒短轮询**：
  - 轮询接口：`calendar/lotteries/{lottery}/snapshot`
  - 在 `open` / `sold_out` 时自动检查变化
  - 发现进度、状态或本人持有节点变化时整页刷新
  - 页面位于后台标签页时跳过本轮请求

### 3.5 阶段 6 回归复核状态

2026-05-30 已通过 `seat-ssh` 对测试服做一轮只读/控制器回归复核：

- `AuditController::operationsJson()` 返回的抽奖行动带 `is_lottery = true`、`lottery_id`、`can_audit = true`。
- `AuditController::membersJson(184)` 返回抽奖成员明细，能看到购买扣费与「审查整行动清零」流水；抽奖 PAP 行 `ship_type_id = 0` 时显示船型 `—`。
- 普通行动 `operation_id = 172` 已有 3 条「审查整行动清零」流水，且对应 `paps.value` 全部为 `0.00`。
- 未终态抽奖 `operation_id = 185` / `lottery_id = 4` 为 `open`，调用清零逻辑返回 HTTP `422`，拒绝路径生效。
- 零值排行榜局部视图已能渲染 `width: 0%`，不再触发除零。

随后用户确认阶段 6 浏览器 UI 验证无误；阶段 1–6 暂无已知阻塞项。后续如新建负数 lottery 样本，可顺手再复核“消费 PAP”文案。

### 3.6 已确认修复生效的 bug

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

### 4.3 审查整行动清零的语义

- 目标：把当前 action 下**现有成员的最终 PAP 值**冲正为 0
- 实现方式：对每个 `paps.value != 0` 的成员追加一条反向 `PapAdjustment`
- 不删除既有历史流水；清零后仍能在审查弹窗的 history 里看见原始记录和清零记录
- 已是 0 的成员会跳过
- 若 action 关联 lottery 且状态仍是 `open` / `sold_out`，后端会拒绝执行

### 4.4 当前仍未做的事

- 军团 PAP 统计 / 导出语义调整（阶段 7，仍延后）
- 自动开奖
- 到期自动退款
- 手动选号

---

## 5. 测试服连接方式（不落密钥）

本仓库不保存测试服密钥与明文凭据；后续如需从当前工作区旁路 SSH 到测试服，优先通过本机项目 `E:\AI\All projects\seat-ssh` 连接。

### 5.1 SSH 连接约定

连接前必须先向用户说明：

- 将连接的服务器名称；
- 将连接的服务器 IP；
- 本次会执行只读检查还是写入操作。

在未说明目标服务器/IP 前，不要直接发起 SSH 连接。也不要把实际 IP、用户名、私钥内容写入本仓库文档或提交到 git。

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

本次 `871b17c` 审查清零 + 短轮询改动只涉及 PHP / Blade / lang，**不需要**新增 migration，也**不需要** publish assets。

---

## 7. 下一位接手人的推荐动作

按优先级建议：

1. 打开 `docs/PAP超网抽奖宿主验证清单.md`
2. 阶段 2–6 已完成验证，暂无已知阻塞项；后续如新建或保留一个未清零、未退款的负数 lottery 样本，可顺手确认总额列显示“消费 PAP”文案。
3. 阶段 3.5 的 30 角色并发购买已验证通过，可按需复现。
4. 如需回归抽奖主流程，可抽样复测：
   - 创建一条小抽奖
   - 买 1～2 个节点并核对 `pap_adjustments` / `paps.value` / `lottery_nodes`
   - 售满开奖（`draw_mode = sold_out`）
   - 提前开奖（`draw_mode = early`、未售节点 `voided_at`）
   - 取消并退款
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

### 用例 D：整行动 PAP 清零

- 选一个普通 action，确保至少 2 人且 `paps.value` 有非 0 值
- 在 `/calendar/audit` 打开成员明细
- 点「整行动 PAP 清零」
- 应为每个非 0 成员追加一条反向 adjustment，并使该 action 下 `paps.value` 归零
- 对未终态 lottery action 执行时应被拒绝

### 用例 E：30 角色并发购买模拟

- 抽奖标题：`并发测试`
- 节点数：`31`
- 单价：`1.00`
- 每人上限：`1`
- 临时创建 30 个测试角色并并发各买 1 个节点
- 预期：
  - 30/30 成功
  - `sold_count = 30`
  - 没有重复节点分配
  - `pap_adjustments` 共 30 条、总和 `-30.00`
  - `kassie_calendar_paps` 在该抽奖 operation 下共 30 行、总和 `-30.00`
  - 清理测试数据后，抽奖恢复为 `open` 且 `sold_count = 0`

---

## 9. 相关文档

- `docs/PAP超网抽奖功能计划.md`
- `docs/PAP超网抽奖宿主验证清单.md`
- `README.md`（Packagist 安装 / 更新方式）

交接时优先读本文件 + 验证清单，其次再回到计划文档看设计原意。
