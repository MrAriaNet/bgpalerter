<?php
/**
 * BGP Alerter Configuration
 * Configure your settings here
 */

return [
    // Database Configuration
    'database' => [
        'type' => 'sqlite', // 'sqlite' or 'mysql'
        'sqlite_path' => __DIR__ . '/data/bgp_monitor.db',
        'mysql' => [
            'host' => 'localhost',
            'dbname' => 'bgp_monitor',
            'username' => 'root',
            'password' => ''
        ]
    ],

    // RIPE API Configuration
    'ripe' => [
        'api_url' => 'https://stat.ripe.net/data/bgp-state/data.json',
        'timeout' => 30
    ],

    // Telegram Bot Configuration
    'telegram' => [
        'enabled' => true, // Set to false to disable Telegram alerts (only use web dashboard)
        'bot_token' => 'YOUR_BOT_TOKEN_HERE', // Get from @BotFather
        'chat_id' => 'YOUR_CHAT_ID_HERE' // Your Telegram chat ID
    ],

    // Monitoring Configuration
    'monitoring' => [
        'check_interval' => 300, // Check every 5 minutes (in seconds)
        'prefixes' => [
            // Add prefixes to monitor
            // Format: 'prefix' => 'description'
            '8.8.8.0/24' => 'Google DNS',
            '1.1.1.0/24' => 'Cloudflare DNS',
            // Add more prefixes as needed
        ],
        'asn' => null, // Optional: Filter by specific ASN
        'resource' => null, // Optional: Filter by resource (ASN or prefix)
        'show_asn_names' => true // Set to true to resolve and display ASN names (Upstream names)
    ],

    // Web Dashboard Configuration
    'dashboard' => [
        'title' => 'BGP Route Monitor',
        'timezone' => 'Asia/Tehran', // Iran timezone
        'items_per_page' => 50
    ],

    // API Configuration
    'api' => [
        'enabled' => true, // Enable/disable API
        'tokens' => [
            // Format: 'token' => 'user_name'
            // Example tokens - CHANGE THESE!
            'your-secret-token-1' => 'User 1',
            'your-secret-token-2' => 'User 2',
            // Add more tokens as needed
        ]
    ]
];

