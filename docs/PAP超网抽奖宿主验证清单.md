# PAP 超网抽奖 — 宿主验证清单

本清单用于在 **SeAT 宿主环境**（`/var/www/seat`，Laravel 10 / PHP 8.4 / MySQL / Redis）验证抽奖第一版（阶段 1–6）的实际运行效果。

本仓库代码无法在本机直接运行（PHP / MySQL 都在宿主侧），因此所有迁移、事务、PAP 回写逻辑都必须在宿主实测确认。

> 约定：标注「**宿主命令**」的在 SeAT 根目录执行；标注「**SQL**」的在宿主数据库执行（`mysql` 或任意客户端）。下文 SQL 里的 `:lottery_id` / `:op_id` 等请替换为实际值。

## 当前交接进度（2026-05）

- **代码已推到分支**：`docs/pap-hypernet-lottery-plan`
- **测试服已确认部署版本**：`b04dfbe`（含「提前开奖」功能）
- **已实测通过**：
  - 阶段 1 结构类检查（字段精度 / enum / 三张抽奖表 / 索引）
  - 抽奖创建阻塞性 bug 修复：保留 tag 补 `bg_color` / `text_color`
  - 购买前阻塞性 bug 修复：抽奖 PAP 首次创建补 `ship_type_id = 0`
- **待继续实测**：
  - 阶段 2 创建后页面/落库完整核对
  - 阶段 3 购买节点与负数扣费
  - 阶段 4 售满开奖
  - 阶段 4.4 提前开奖（`draw_mode = early`、未售节点 `voided_at`）
  - 阶段 5 取消退款
  - 阶段 6 行动审查友好化

## 测试服连接约定

- Web 入口：`http://ylxh.de`
- 如需从当前工作区旁路 SSH 到测试服，复用兄弟仓库 `seat-fitting` 的辅助脚本：

**本机命令**
```bash
cd ../seat-fitting
bash scripts/ssh-seat -t test 'your command here'
```

说明：
- 凭据文件在 `../seat-fitting/.creds.test`，私钥路径也在该文件第 4 行配置；**不要**把实际 IP、账号、私钥内容再写进本仓库文档。
- 在宿主上跑 `php artisan` 时，优先使用 `sudo -u www-data php artisan ...`，避免 root 直跑造成权限问题。

---

## 0. 前置：把抽奖分支部署到宿主

1. 让宿主的插件代码切到 / 更新到分支 `docs/pap-hypernet-lottery-plan`（path repository 或重新 `composer require`/`composer update` 对应分支）。
2. 清缓存并发布静态资源：

**宿主命令**
```bash
php artisan route:clear
php artisan config:clear
php artisan view:clear
php artisan vendor:publish --tag=calendar --force   # 若该 tag 不适用，按现有插件发布方式发布 assets
```

3. 确认路由已注册：

**宿主命令**
```bash
php artisan route:list | egrep 'lotteries'
```

应能看到 7 条：
```
GET   calendar/lotteries
GET   calendar/lotteries/create
POST  calendar/lotteries
GET   calendar/lotteries/{lottery}
POST  calendar/lotteries/{lottery}/purchase
POST  calendar/lotteries/{lottery}/draw
POST  calendar/lotteries/{lottery}/cancel
```

---

## 1. 阶段 1：迁移与表结构

### 1.1 跑迁移

**宿主命令**
```bash
php artisan migrate
php artisan migrate:status | egrep 'widen_pap_value|analytics_enum|create_lottery_tables'
```

预期三条都为 `Ran`：

- `2026_05_29_100000_widen_pap_value_precision`
- `2026_05_29_100100_extend_calendar_tags_analytics_enum`
- `2026_05_29_100200_create_lottery_tables`

### 1.2 字段精度已拓宽

**SQL**
```sql
SHOW COLUMNS FROM kassie_calendar_paps LIKE 'value';
SHOW COLUMNS FROM kassie_calendar_pap_adjustments LIKE 'value';
```

两者 `Type` 都应为 `decimal(8,2)`（原先分别是 `decimal(5,2)` / `decimal(6,2)`）。

### 1.3 analytics enum 已含 lottery

**SQL**
```sql
SHOW COLUMNS FROM calendar_tags LIKE 'analytics';
```

`Type` 应包含 `'lottery'`：
```
enum('strategic','pvp','mining','other','untracked','lottery')
```

### 1.4 三张抽奖表已建

**SQL**
```sql
SHOW TABLES LIKE 'kassie_calendar_lotter%';
-- 期望：kassie_calendar_lotteries / _lottery_prizes / _lottery_nodes

SHOW INDEX FROM kassie_calendar_lotteries;      -- operation_id 唯一、status、created_by 索引
SHOW INDEX FROM kassie_calendar_lottery_nodes;  -- (lottery_id,node_number) 唯一
```

> 旧数据安全性：迁移只扩大字段范围、只新增 enum 取值，属前向安全，不应影响既有 PAP 数据。可顺手 `SELECT COUNT(*) FROM kassie_calendar_paps;` 对比迁移前后行数不变。

---

## 2. 阶段 2：创建抽奖

### 2.1 入口

- 侧边栏应出现 **PAP 抽奖**（骰子图标）。
- 进入 `/calendar/lotteries`，点「创建抽奖」。

### 2.2 建一个小抽奖（建议参数）

- 标题：`验证测试`
- 总节点数：`5`
- 单节点 PAP 价格：`1.00`
- 每人最多节点数：`3`
- 奖品：至少 2 个（如「一等奖」「二等奖」），便于验证多奖顺序抽
- 是否允许重复中奖：先选**不允许**

提交后应跳到详情页，状态为 **进行中（open）**。

### 2.3 落库检查

**SQL**
```sql
SELECT id, operation_id, title, node_count, node_price, max_nodes_per_user,
       allow_repeat_winners, status, created_by_character_id
FROM kassie_calendar_lotteries ORDER BY id DESC LIMIT 1;
-- 记下 id 作为 :lottery_id，operation_id 作为 :op_id

SELECT COUNT(*) FROM kassie_calendar_lottery_nodes WHERE lottery_id = :lottery_id;
-- 应等于 node_count（5），且 user_id / purchased_at 全为 NULL

SELECT sort_order, name FROM kassie_calendar_lottery_prizes WHERE lottery_id = :lottery_id ORDER BY sort_order;
```

### 2.4 保留 tag 与绑定

**SQL**
```sql
SELECT id, name, quantifier, analytics FROM calendar_tags WHERE analytics = 'lottery';
-- 应只有一条，quantifier = 0.00

SELECT * FROM calendar_tag_operation WHERE operation_id = :op_id;
-- 该 operation 应绑定上面的 lottery tag
```

---

## 3. 阶段 3：购买节点与扣费

> 用一个**普通成员**账号（已有 ≥ 5 PAP 可花，起始日 2026-01-01 之后）登录，进入该抽奖详情页。

### 3.1 购买前先记基线

**SQL**
```sql
-- 该用户所有关联角色、起始日以来的可用 PAP（与代码口径一致：join_time、不 floor）
SELECT COALESCE(SUM(value),0) AS available_pap
FROM kassie_calendar_paps
WHERE join_time >= '2026-01-01'
  AND character_id IN ( /* 该用户全部 character_id，见下 */ );

-- 取某用户的全部 character_id
SELECT character_id FROM refresh_tokens WHERE user_id = :user_id;
```

### 3.2 买 2 个节点

页面购买区显示「我的可用 PAP / 剩余 / 已购 / 单价」，输入数量 `2` 购买。成功后整页刷新，节点格子里 2 个变绿（我购买的）。

### 3.3 扣费正确性

**SQL**
```sql
-- 应出现一条负数聚合记录，value = -(2 * 1.00) = -2.00
SELECT operation_id, character_id, value, reason, created_by_character_id
FROM kassie_calendar_pap_adjustments
WHERE operation_id = :op_id ORDER BY id DESC LIMIT 1;

-- paps.value 应被回写为 base(0) + 调整合计 = -2.00
SELECT operation_id, character_id, value, join_time
FROM kassie_calendar_paps WHERE operation_id = :op_id;

-- 节点归属已更新
SELECT node_number, user_id, character_id, pap_adjustment_id, purchased_at
FROM kassie_calendar_lottery_nodes
WHERE lottery_id = :lottery_id AND user_id IS NOT NULL;
```

要点：
- `paps.value` 为负，且等于该用户在该 op 下所有 `pap_adjustments.value` 之和（base=0）。
- 购买的节点 `pap_adjustment_id` 指向上面那条负数记录。
- 可用 PAP（3.1 的查询）应比购买前减少 2.00。

### 3.4 校验项快速验证（可选）

- 超过每人上限（已购 2，再买 2，上限 3）→ 应报「超过每人上限」。
- 数量超过剩余 → 应报「剩余节点不足」。
- 余额不足（换个零 PAP 账号）→ 应报「可用 PAP 不足」。

---

## 4. 阶段 4：售完后手动开奖

### 4.1 买满触发 sold_out

继续购买直到 5 个节点全部售出（可用 3.2 的成员补够，或换账号买；注意每人上限 3）。售完后：

**SQL**
```sql
SELECT status FROM kassie_calendar_lotteries WHERE id = :lottery_id;
-- 应为 sold_out
```

详情页此时应出现 **开奖** 按钮（需 `calendar.create` 权限的 FC / 管理员账号才可见）。

### 4.2 开奖

点「开奖」确认。成功后整页刷新：中奖节点金色高亮，奖品列表显示中奖者，页面顶部显示开奖人 / 时间。

**SQL**
```sql
SELECT status, drawn_by_character_id, drawn_at FROM kassie_calendar_lotteries WHERE id = :lottery_id;
-- status = drawn，开奖人 / 时间已填

SELECT sort_order, name, winner_node_number, winner_user_id, winner_character_id, drawn_at
FROM kassie_calendar_lottery_prizes WHERE lottery_id = :lottery_id ORDER BY sort_order;

SELECT draw_log FROM kassie_calendar_lotteries WHERE id = :lottery_id;
-- draw_log 为 JSON，含 draw_mode=sold_out、allow_repeat_winners、rounds[]
```

要点（**不允许重复中奖**时）：
- 两个奖品的 `winner_node_number` 应是**不同节点**。
- 两个奖品的 `winner_user_id` 应是**不同用户**（除非中奖人数不足，候选耗尽时该奖 winner 为 NULL）。

> 如改用「允许重复中奖」再测一期：同一用户可中多个奖，但**同一个节点不会中两次**（`draw_log.rounds` 里 `winner_node_number` 互不相同）。

### 4.3 幂等

对已 `drawn` 的抽奖再次 POST `draw`（或刷新后按钮应已消失）应被拒绝（「只有进行中或已售罄的抽奖才能开奖」）。

### 4.4 提前开奖（凑不满人时，2026-05 新增）

> `open` 状态下凑不满人，FC / 管理员可在已有至少 1 个已售节点的前提下手动「提前开奖」。

1. 另建一期（重复 2.2，建议：节点数 `3`、单价 `1`、每人上限 `3`、2 个奖品、勾选「允许重复中奖」）。
2. 用成员账号只买 **2** 个节点（**留 1 个不卖**），保持 `open`。
3. 详情页管理栏应出现黄色 **提前开奖** 按钮（仅 `open` 且 `sold_count > 0` 时显示；一个都没卖时不显示）。
4. 点「提前开奖」，确认框会提示「未售节点将作废」，确认。

**SQL**
```sql
SELECT status, JSON_EXTRACT(draw_log, '$.draw_mode') AS draw_mode
FROM kassie_calendar_lotteries WHERE id = :lottery_id3;
-- status = drawn，draw_mode = "early"

-- 未售出的那个节点应被作废
SELECT node_number, purchased_at, voided_at
FROM kassie_calendar_lottery_nodes WHERE lottery_id = :lottery_id3 ORDER BY node_number;
-- 已购 2 个：purchased_at 非空、voided_at 空；未售 1 个：voided_at 非空

SELECT sort_order, name, winner_node_number, winner_character_id
FROM kassie_calendar_lottery_prizes WHERE lottery_id = :lottery_id3 ORDER BY sort_order;
-- 中奖节点只会落在已售的 2 个节点上，绝不会是被作废的未售节点
```

要点：
- `draw_mode = early`；未售节点 `voided_at` 被填，且**不出现在任何奖品的中奖节点里**。
- 一个节点都没售出时尝试提前开奖应被拒绝（「还没有任何节点售出，无法开奖」）。
- `paps.value` 不因开奖改变（开奖只动节点 / 奖品 / 状态）。

---

## 5. 阶段 5：取消与退款

> 另建一个抽奖（重复 2.2），买几个节点（重复 3.2），保持 `open` 或 `sold_out`，然后取消。

### 5.1 取消并退款

详情页管理栏点「取消并退款」确认。

**SQL**
```sql
SELECT status FROM kassie_calendar_lotteries WHERE id = :lottery_id2;
-- cancelled

-- 每个购买者应新增一条正数退款记录
SELECT character_id, value, reason, created_by_character_id
FROM kassie_calendar_pap_adjustments
WHERE operation_id = :op_id2 ORDER BY id DESC;

-- 退款后该 op 下每个购买者的 paps.value 应回到 0（扣费 + 退款净额为 0）
SELECT character_id, value FROM kassie_calendar_paps WHERE operation_id = :op_id2;

-- 节点应标记已退款
SELECT COUNT(*) FROM kassie_calendar_lottery_nodes
WHERE lottery_id = :lottery_id2 AND refunded_at IS NOT NULL;
```

要点：
- 退款金额（正数）= 该用户已购节点数 × 单价，与扣费口径一致。
- 净额归零：`paps.value` 回到 0。
- 该购买者的可用 PAP 恢复到购买前水平。

### 5.2 幂等

对已 `cancelled` 的抽奖再次 POST `cancel` 应被拒绝（「当前状态不可取消」），且不产生第二条退款。

---

## 6. 阶段 6：行动审查友好化

进入 `/calendar/audit`：

- 抽奖行动这一行标题前应有 **抽奖** 徽标。
- 「单 PAP」列显示 **—**（而非 0）。
- 「总额」列在为负时显示 **消费 PAP xxx**（已开奖 / 进行中且有净消费时）。
- 点开成员明细弹窗，标题栏应有 **查看抽奖详情** 链接（新标签打开对应抽奖页）。
- 弹窗内每个成员的流水（adjustments）应能看到购买扣费（「购买抽奖节点 N 个：#...」）和退款记录。

> 已全额退款的抽奖净额为 0，「总额」会显示 0.00（非「消费 PAP」），属预期。

---

## 7. 回归检查（确认没影响既有 PAP）

- 普通行动的 PAP 发放、行动审查奖惩、角色 / 军团 PAP 统计页应照常工作。
- **已知现象（非缺陷，阶段 7 处理）**：抽奖消费是负数 `paps.value`，会即时进入角色 / 军团 PAP 统计页，使统计把「获得」与「抽奖消费」混在一起。这是计划 §5.3 / 阶段 7 专门要处理的，目前按决策延后。

---

## 8. 回滚（如需）

三条迁移都写了 `down()`，可回滚：

**宿主命令**
```bash
php artisan migrate:rollback --step=3
```

注意：
- enum 回退前会先把 `analytics = 'lottery'` 的 tag 归位为 `untracked`，再收窄 enum。
- 字段精度回退到 `decimal(5,2)` / `decimal(6,2)` 前，请确认没有超出旧范围的值（抽奖累计消费可能 < -999.99），否则应先清理相关数据再回滚。

---

## 附：问题上报要点

若某步结果与预期不符，回报时请附带：

1. 是哪一阶段、哪条 SQL / 哪个页面操作。
2. 期望值 vs 实际值。
3. 相关行的原始数据（`lotteries` / `lottery_nodes` / `paps` / `pap_adjustments` 对应行）。
4. `storage/logs/laravel.log` 中对应时间的报错（如有）。
