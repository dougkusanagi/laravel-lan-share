<?php

return [
    'laravel_port' => (int) env('LAN_SHARE_LARAVEL_PORT', 8080),

    'vite_port' => (int) env('LAN_SHARE_VITE_PORT', 5174),

    'port_search_limit' => (int) env('LAN_SHARE_PORT_SEARCH_LIMIT', 20),

    'replace_existing' => (bool) filter_var(env('LAN_SHARE_REPLACE_EXISTING', true), FILTER_VALIDATE_BOOLEAN),

    'vite_config' => env('LAN_SHARE_VITE_CONFIG', 'vite.lan.config.ts'),

    'firewall_rule_prefix' => 'DougKusanagi-LaravelLanShare',

    'wsl_distro' => env('WSL_DISTRO_NAME'),

    'host' => env('LAN_SHARE_HOST'),

    'qr' => [
        'enabled' => (bool) filter_var(env('LAN_SHARE_QR', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'sharing' => [
        'whatsapp' => (bool) filter_var(env('LAN_SHARE_WHATSAPP', true), FILTER_VALIDATE_BOOLEAN),
        'availability_message' => env('LAN_SHARE_AVAILABILITY_MESSAGE', 'Disponível somente para dispositivos conectados à mesma rede local.'),
    ],

    'share_page' => [
        'enabled' => (bool) filter_var(env('LAN_SHARE_PAGE', true), FILTER_VALIDATE_BOOLEAN),
        'path' => env('LAN_SHARE_PAGE_PATH', '__lan-share'),
    ],

    'agent' => [
        'enabled' => (bool) filter_var(env('LAN_SHARE_AGENT', true), FILTER_VALIDATE_BOOLEAN),
        'fallback_to_script' => (bool) filter_var(env('LAN_SHARE_AGENT_FALLBACK', false), FILTER_VALIDATE_BOOLEAN),
        'lease_seconds' => max(5, (int) env('LAN_SHARE_AGENT_LEASE_SECONDS', 12)),
        'heartbeat_interval' => max(2, (int) env('LAN_SHARE_AGENT_HEARTBEAT_INTERVAL', 3)),
        'idle_timeout' => max(30, (int) env('LAN_SHARE_AGENT_IDLE_TIMEOUT', 300)),
    ],
];
