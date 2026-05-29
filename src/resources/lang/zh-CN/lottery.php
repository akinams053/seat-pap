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
    'purchase_coming_soon' => '购买功能将在后续阶段开放。',
    'winner_label' => '中奖者',
    'winner_node' => '中奖节点',
    'not_drawn_yet' => '尚未开奖',

];
