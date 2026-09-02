<?php
declare(strict_types=1);

require __DIR__ . '/src/Response.php';
require __DIR__ . '/src/Db.php';
require __DIR__ . '/src/Groq.php';
require __DIR__ . '/src/Handlers.php';

set_exception_handler(static function (Throwable $e): void {
    error_log($e->getMessage());
    Response::error('Internal error: ' . $e->getMessage(), 502);
});

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    Response::error('Server not configured: copy config.example.php to config.php', 500);
}
$config = require $configPath;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    Response::json([], 204);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = '/' . trim($path, '/');

$docs = static fn (): array => [
    'name' => 'Excuse-to-Email API',
    'description' => 'Turns messy excuses into polished emails and defuses angry messages. Powered by Groq.',
    'auth' => 'Send your key in the X-Api-Key header (or ?api_key=). Get one from the admin via POST /keys.',
    'endpoints' => [
        'GET  /health' => 'Liveness check.',
        'POST /keys' => 'Create an API key. Requires X-Admin-Secret header. Body: {"label": "who is this for"}',
        'GET  /me' => 'Usage for your key today.',
        'POST /excuse' => [
            'body' => [
                'excuse' => '(required) the messy truth, e.g. "overslept, cat knocked over the coffee, missed standup"',
                'recipient' => 'default "my manager"',
                'sender' => 'your name for the sign-off',
                'context' => 'anything else the email should mention',
                'tone' => 'one of: ' . implode(', ', Handlers::TONES),
                'honesty' => '"honest" (default) or "vague"',
            ],
            'returns' => ['subject', 'body', 'tip'],
        ],
        'POST /tone' => [
            'body' => [
                'text' => '(required) the message you are about to regret sending',
                'tone' => 'one of: ' . implode(', ', Handlers::TONES) . ' (default calm)',
                'audience' => 'e.g. "my landlord", "a coworker"',
            ],
            'returns' => ['rewritten', 'changes', 'anger_before', 'anger_after'],
        ],
    ],
];

if ($path === '/' && $method === 'GET') {
    Response::ok($docs());
}
if ($path === '/health') {
    Response::ok(['time' => gmdate('c')]);
}

$db = new Db($config['db_dsn'], $config['db_user'], $config['db_pass']);

$readBody = static function (): array {
    $raw = file_get_contents('php://input') ?: '';
    if (trim($raw) === '') {
        return $_POST;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        Response::error('Body must be a JSON object', 400);
    }
    return $data;
};

if ($path === '/keys') {
    if ($method !== 'POST') {
        Response::error('Method not allowed', 405);
    }
    $given = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? '';
    if ($given === '' || !hash_equals((string) $config['admin_secret'], $given)) {
        Response::error('Invalid admin secret', 401);
    }
    $label = $readBody()['label'] ?? '';
    $key = $db->createKey(is_string($label) ? $label : '');
    Response::json(['ok' => true, 'api_key' => $key, 'daily_limit' => $config['daily_limit']], 201);
}

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');
if (!is_string($apiKey) || $apiKey === '' || !$db->keyExists($apiKey)) {
    Response::error('Missing or invalid API key. Send it in the X-Api-Key header.', 401);
}

if ($path === '/me') {
    $used = $db->usageToday($apiKey);
    Response::ok(['used_today' => $used, 'daily_limit' => $config['daily_limit'], 'remaining' => max(0, $config['daily_limit'] - $used)]);
}

if (!in_array($path, ['/excuse', '/tone'], true)) {
    Response::error('Not found. GET / for docs.', 404);
}
if ($method !== 'POST') {
    Response::error('Method not allowed', 405);
}

$used = $db->usageToday($apiKey);
if ($used >= (int) $config['daily_limit']) {
    Response::error('Daily limit reached. Resets at 00:00 UTC.', 429, ['daily_limit' => $config['daily_limit']]);
}

$handlers = new Handlers(new Groq($config['groq_api_key'], $config['groq_model']));
$input = $readBody();
$result = $path === '/excuse' ? $handlers->excuse($input) : $handlers->tone($input);
$db->logUsage($apiKey, ltrim($path, '/'));

Response::ok($result + ['remaining_today' => max(0, (int) $config['daily_limit'] - $used - 1)]);
