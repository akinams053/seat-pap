<?php

return [

    'menu_title' => 'PAP Lottery',
    'list_title' => 'PAP Lottery',
    'create_title' => 'Create Lottery',
    'detail_title' => 'Lottery Detail',

    // list
    'create_btn' => 'Create Lottery',
    'back_to_list' => 'Back to list',
    'col_title' => 'Title',
    'col_status' => 'Status',
    'col_prizes' => 'Prizes',
    'col_progress' => 'Nodes',
    'col_price' => 'Node price',
    'col_fc' => 'Created by',
    'col_actions' => 'Actions',
    'view_btn' => 'View',
    'no_lotteries' => 'No lotteries yet.',

    // status
    'status_open' => 'Open',
    'status_sold_out' => 'Sold out',
    'status_drawn' => 'Drawn',
    'status_cancelled' => 'Cancelled',

    // create form
    'form_title' => 'Lottery title',
    'form_title_ph' => 'e.g. Weekend HyperNet jackpot',
    'form_node_count' => 'Total nodes',
    'form_node_price' => 'PAP price per node',
    'form_max_per_user' => 'Max nodes per user',
    'form_max_per_user_hint' => 'Leave empty for no limit; cannot exceed total nodes.',
    'form_allow_repeat' => 'Allow the same user to win more than once',
    'form_prizes' => 'Prizes',
    'form_prize_name' => 'Prize name',
    'form_prize_name_ph' => 'e.g. 1st prize — one capital ship',
    'form_prize_desc' => 'Prize description (optional)',
    'form_prize_desc_ph' => 'Optional notes',
    'add_prize' => 'Add prize',
    'remove_prize' => 'Remove',
    'submit_btn' => 'Create lottery',
    'created_success' => 'Lottery created and is now open.',

    // validation errors
    'err_limit_gt_total' => 'Max nodes per user cannot exceed total nodes.',
    'err_no_main_character' => 'No main character found, cannot create lottery.',

    // detail
    'prizes_header' => 'Prizes',
    'nodes_header' => 'Nodes',
    'progress_label' => 'Sold :sold / :total',
    'price_label' => 'Node price',
    'fc_label' => 'Created by',
    'prize_no_desc' => '(no description)',
    'node_unsold' => 'Unsold',
    'node_mine' => 'Mine',
    'node_others' => 'Others',
    'node_winner' => 'Winner',
    'my_nodes' => 'My nodes',
    'my_nodes_none' => 'You have not purchased any nodes yet.',
    'winner_label' => 'Winner',
    'winner_node' => 'Winning node',
    'not_drawn_yet' => 'Not drawn yet',

    // purchase
    'buy_title' => 'Buy nodes',
    'available_pap_label' => 'My available PAP',
    'remaining_label' => 'Remaining nodes',
    'my_owned_label' => 'My nodes',
    'buy_quantity' => 'Quantity',
    'buy_btn' => 'Buy',
    'max_buyable' => 'You can buy up to :n now',
    'buy_confirm' => 'Buy :count node(s) for :spent PAP?',
    'buy_closed' => 'Purchasing is not available in the current status.',
    'buy_no_balance_hint' => 'Not enough available PAP to buy.',
    'purchase_reason' => 'Bought :count lottery node(s): :nodes',
    'purchase_success' => 'Purchased :count node(s) for :spent PAP.',

    // purchase errors
    'err_not_found' => 'Lottery not found.',
    'err_not_open' => 'This lottery is not open for purchase.',
    'err_not_enough_nodes' => 'Not enough nodes left, only :remaining remaining.',
    'err_exceed_limit' => 'Exceeds per-user limit (limit :limit, you own :owned).',
    'err_insufficient_pap' => 'Not enough available PAP: have :available, need :need. Please refresh and retry.',

    // draw
    'manage_title' => 'Management',
    'draw_btn' => 'Draw',
    'draw_confirm' => 'Confirm draw? Results cannot be changed.',
    'draw_success' => 'Draw completed.',
    'drawn_info' => 'Drawn by :by · at :at',
    'err_not_sold_out' => 'Only a fully sold-out lottery can be drawn.',

    // cancel & refund
    'cancel_btn' => 'Cancel & refund',
    'cancel_confirm' => 'Cancel this lottery and refund all buyers? This cannot be undone.',
    'cancel_success' => 'Lottery cancelled, refunded :count node(s).',
    'refund_reason' => 'Lottery cancelled, refunded nodes :nodes',
    'err_not_cancellable' => 'Cannot cancel in the current status (only open or sold-out and not drawn).',

];
