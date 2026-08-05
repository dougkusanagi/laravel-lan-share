<?php

return [
    'laravel_port' => (int) env('LAN_SHARE_LARAVEL_PORT', 8080),

    'vite_port' => (int) env('LAN_SHARE_VITE_PORT', 5174),

    'port_search_limit' => (int) env('LAN_SHARE_PORT_SEARCH_LIMIT', 20),

    'vite_config' => env('LAN_SHARE_VITE_CONFIG', 'vite.lan.config.ts'),

    'firewall_rule_prefix' => 'DougKusanagi-LaravelLanShare',

    'wsl_distro' => env('WSL_DISTRO_NAME'),

    'host' => env('LAN_SHARE_HOST'),

    'qr' => [
        'enabled' => false,
    ],
];
