<?php
declare(strict_types=1);

final class AppConfig
{
    /**
     * Loads config.php if present (InfinityFree / local), otherwise environment variables (Vercel etc).
     *
     * @return array{groq_api_key:string,groq_model:string,admin_secret:string,db_dsn:string,db_user:string,db_pass:string,daily_limit:int,signups_per_ip_per_day:int}
     */
    public static function load(string $filePath): array
    {
        $cfg = is_file($filePath) ? require $filePath : self::fromEnv();

        $required = ['groq_api_key', 'admin_secret', 'db_dsn'];
        foreach ($required as $k) {
            if (empty($cfg[$k])) {
                Response::error("Server not configured: missing $k (set it in config.php or as an env var)", 500);
            }
        }

        return [
            'groq_api_key' => (string) $cfg['groq_api_key'],
            'groq_model' => (string) ($cfg['groq_model'] ?? 'openai/gpt-oss-120b'),
            'admin_secret' => (string) $cfg['admin_secret'],
            'db_dsn' => (string) $cfg['db_dsn'],
            'db_user' => (string) ($cfg['db_user'] ?? ''),
            'db_pass' => (string) ($cfg['db_pass'] ?? ''),
            'daily_limit' => (int) ($cfg['daily_limit'] ?? 50),
            'signups_per_ip_per_day' => (int) ($cfg['signups_per_ip_per_day'] ?? 3),
        ];
    }

    /** @return array<string, mixed> */
    private static function fromEnv(): array
    {
        $env = static fn (string $k): string => (string) (getenv($k) ?: ($_ENV[$k] ?? ''));

        $dsn = $env('DB_DSN');
        $user = $env('DB_USER');
        $pass = $env('DB_PASS');
        $url = $env('DATABASE_URL') ?: $env('POSTGRES_URL');
        if ($dsn === '' && $url !== '') {
            [$dsn, $user, $pass] = self::dsnFromUrl($url);
        }

        return [
            'groq_api_key' => $env('GROQ_API_KEY'),
            'groq_model' => $env('GROQ_MODEL'),
            'admin_secret' => $env('ADMIN_SECRET'),
            'db_dsn' => $dsn,
            'db_user' => $user,
            'db_pass' => $pass,
            'daily_limit' => $env('DAILY_LIMIT') ?: 50,
            'signups_per_ip_per_day' => $env('SIGNUPS_PER_IP_PER_DAY') ?: 3,
        ];
    }

    /**
     * postgres://user:pass@host:5432/db?sslmode=require  ->  pgsql:host=...;port=...;dbname=...;sslmode=require
     * mysql://user:pass@host/db                          ->  mysql:host=...;dbname=...;charset=utf8mb4
     *
     * @return array{0:string,1:string,2:string}
     */
    private static function dsnFromUrl(string $url): array
    {
        $p = parse_url($url);
        if ($p === false || empty($p['host'])) {
            Response::error('DATABASE_URL is not a valid URL', 500);
        }
        $scheme = strtolower($p['scheme'] ?? '');
        $driver = in_array($scheme, ['postgres', 'postgresql', 'pgsql'], true) ? 'pgsql' : 'mysql';
        $parts = ['host=' . $p['host']];
        if (!empty($p['port'])) {
            $parts[] = 'port=' . $p['port'];
        }
        $parts[] = 'dbname=' . ltrim($p['path'] ?? '', '/');
        if ($driver === 'pgsql') {
            parse_str($p['query'] ?? '', $q);
            $parts[] = 'sslmode=' . (is_string($q['sslmode'] ?? null) ? $q['sslmode'] : 'require');
        } else {
            $parts[] = 'charset=utf8mb4';
        }
        return [
            $driver . ':' . implode(';', $parts),
            urldecode($p['user'] ?? ''),
            urldecode($p['pass'] ?? ''),
        ];
    }
}
