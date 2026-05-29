<?php

return [

    'menu_title' => 'PAP 抽奖',
    'list_title' => 'PAP 抽奖',
    'create_title' => '创建抽奖',
    'detail_title' => '抽奖详情',

    // 列表
    'create_btn' => '创建抽奖',
    'back_to_list' => '返回列表',
    'col_title' => '标题',
    'col_status' => '状态',
    'col_prizes' => '奖品数',
    'col_progress' => '节点进度',
    'col_price' => '单节点价格',
    'col_fc' => '创建 FC',
    'col_actions' => '操作',
    'view_btn' => '查看',
    'no_lotteries' => '暂无抽奖。',

    // 状态
    'status_open' => '进行中',
    'status_sold_out' => '已售罄',
    'status_drawn' => '已开奖',
    'status_cancelled' => '已取消',

    // 创建表单
    'form_title' => '抽奖标题',
    'form_title_ph' => '例如：周末超网大奖',
    'form_node_count' => '总节点数',
    'form_node_price' => '单节点 PAP 价格',
    'form_max_per_user' => '每人最多节点数',
    'form_max_per_user_hint' => '留空表示不限制；不得超过总节点数。',
    'form_allow_repeat' => '允许同一用户重复中奖',
    'form_prizes' => '奖品列表',
    'form_prize_name' => '奖品名称',
    'form_prize_name_ph' => '例如：一等奖 — 旗舰一艘',
    'form_prize_desc' => '奖品说明（可选）',
    'form_prize_desc_ph' => '补充说明，可留空',
    'add_prize' => '添加奖品',
    'remove_prize' => '移除',
    'submit_btn' => '创建抽奖',
    'created_success' => '抽奖创建成功，已进入“进行中”状态。',

    // 校验错误
    'err_limit_gt_total' => '每人最多节点数不能超过总节点数。',
    'err_no_main_character' => '未找到主角色，无法创建抽奖。',

    // 详情
    'prizes_header' => '奖品',
    'nodes_header' => '节点',
    'progress_label' => '已售 :sold / :total',
    'price_label' => '单节点价格',
    'fc_label' => '创建 FC',
    'prize_no_desc' => '（无说明）',
    'node_unsold' => '未售',
    'node_mine' => '我购买的',
    'node_others' => '他人已购',
    'node_winner' => '中奖',
    'my_nodes' => '我的节点',
    'my_nodes_none' => '你还没有购买任何节点。',
    'winner_label' => '中奖者',
    'winner_node' => '中奖节点',
    'not_drawn_yet' => '尚未开奖',

    // 购买区
    'buy_title' => '购买节点',
    'available_pap_label' => '我的可用 PAP',
    'remaining_label' => '剩余节点',
    'my_owned_label' => '我已购节点',
    'buy_quantity' => '购买数量',
    'buy_btn' => '购买',
    'max_buyable' => '本次最多可购 :n 个',
    'buy_confirm' => '确认购买 :count 个节点，花费 :spent PAP？',
    'buy_closed' => '当前状态不可购买。',
    'buy_no_balance_hint' => '可用 PAP 不足，无法购买。',
    'purchase_reason' => '购买抽奖节点 :count 个：:nodes',
    'purchase_success' => '购买成功：:count 个节点，花费 :spent PAP。',

    // 购买错误
    'err_not_found' => '抽奖不存在。',
    'err_not_open' => '该抽奖当前不可购买（非进行中状态）。',
    'err_not_enough_nodes' => '剩余节点不足，当前仅剩 :remaining 个。',
    'err_exceed_limit' => '超过每人上限（上限 :limit，你已购 :owned）。',
    'err_insufficient_pap' => '可用 PAP 不足：当前 :available，需要 :need。请刷新后重试。',

    // 开奖
    'manage_title' => '管理操作',
    'draw_btn' => '开奖',
    'draw_confirm' => '确认开奖？开奖结果不可更改。',
    'draw_success' => '开奖完成。',
    'drawn_info' => '开奖人 :by · 开奖时间 :at',
    'err_not_sold_out' => '只有节点全部售罄的抽奖才能开奖。',

];
