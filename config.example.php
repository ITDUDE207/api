<?php
// Copy to config.php and fill in. config.php is git-ignored and blocked by .htaccess.
return [
    // https://console.groq.com/keys
    'groq_api_key' => 'gsk_...',
    'groq_model'   => 'llama-3.3-70b-versatile',

    // Secret required in the X-Admin-Secret header to create API keys via POST /keys.
    'admin_secret' => 'change-me-to-something-long',

    // InfinityFree: find these in the control panel under "MySQL Databases".
    // Example DSN: mysql:host=sqlXXX.infinityfree.com;dbname=if0_12345678_excuse;charset=utf8mb4
    // Local dev:   sqlite:/absolute/path/to/dev.sqlite
    'db_dsn'  => 'mysql:host=sqlXXX.infinityfree.com;dbname=if0_XXXXXXXX_excuse;charset=utf8mb4',
    'db_user' => 'if0_XXXXXXXX',
    'db_pass' => '',

    // Requests allowed per API key per UTC day.
    'daily_limit' => 50,

    // Self-service signups (POST /signup) allowed per IP per UTC day.
    'signups_per_ip_per_day' => 3,
];
