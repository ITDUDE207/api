<?php
declare(strict_types=1);

final class Db
{
    private PDO $pdo;
    private bool $sqlite;

    public function __construct(string $dsn, string $user, string $pass)
    {
        $this->sqlite = str_starts_with($dsn, 'sqlite:');
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $id = $this->sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $now = $this->sqlite ? "DEFAULT (datetime('now'))" : 'DEFAULT CURRENT_TIMESTAMP';

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (
            id $id,
            api_key VARCHAR(64) NOT NULL UNIQUE,
            label VARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL $now
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS usage_log (
            id $id,
            api_key VARCHAR(64) NOT NULL,
            endpoint VARCHAR(32) NOT NULL,
            used_on DATE NOT NULL,
            created_at DATETIME NOT NULL $now
        )");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_usage_key_day ON usage_log (api_key, used_on)");
    }

    public function createKey(string $label): string
    {
        $key = 'ex_' . bin2hex(random_bytes(20));
        $stmt = $this->pdo->prepare('INSERT INTO api_keys (api_key, label) VALUES (?, ?)');
        $stmt->execute([$key, substr($label, 0, 100)]);
        return $key;
    }

    public function keyExists(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM api_keys WHERE api_key = ?');
        $stmt->execute([$key]);
        return (bool) $stmt->fetchColumn();
    }

    public function usageToday(string $key): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM usage_log WHERE api_key = ? AND used_on = ?');
        $stmt->execute([$key, gmdate('Y-m-d')]);
        return (int) $stmt->fetchColumn();
    }

    public function logUsage(string $key, string $endpoint): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO usage_log (api_key, endpoint, used_on) VALUES (?, ?, ?)');
        $stmt->execute([$key, $endpoint, gmdate('Y-m-d')]);
    }
}
