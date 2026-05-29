<?php

return [
    'calendar' => [
        'name' => 'calendar',
        'label' => 'calendar::seat.plugin_name',
        'icon' => 'fas fa-calendar-alt',
        'route_segment' => 'calendar',
        'permission' => 'calendar.view',
        'entries' => [
            [
                'name' => 'Operations',
                'label' => 'calendar::seat.operations',
                'icon' => 'fas fa-calendar-day',
                'route' => 'operation.index',
                'permission' => 'calendar.view'
            ],
            [
                'name' => 'Audit',
                'label' => 'calendar::seat.audit',
                'icon' => 'fas fa-clipboard-check',
                'route' => 'audit.index',
                'permission' => 'calendar.view'
            ],
            [
                'name' => 'Lottery',
                'label' => 'calendar::lottery.menu_title',
                'icon' => 'fas fa-dice',
                'route' => 'lottery.index',
                'permission' => 'calendar.view'
            ],
            [
                'name' => 'Settings',
                'label' => 'calendar::seat.settings',
                'icon' => 'fas fa-cog',
                'route' => 'setting.index',
                'permission' => 'calendar.setup'
            ],
            [
                'name' => 'PAP Shop',
                'label' => 'calendar::seat.pap_shop',
                'icon' => 'fas fa-shopping-cart',
                'route' => 'calendar.shop.redirect',
                'permission' => 'calendar.view'
            ]
        ]
    ]
];
