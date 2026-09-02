<?php
// src/Adapters/SqliteAdminLoginThrottleStore.php
// SQLite に管理ログイン試行の制限状態を安全に保存するアダプタ。

declare(strict_types=1);

namespace Relink\Resolver\Adapters;

use PDO;
use Relink\Resolver\Ports\AdminLoginThrottleStore;

final class SqliteAdminLoginThrottleStore implements AdminLoginThrottleStore
{
    private PDO $pdo;

    public function __construct(string $databasePath)
    {
        $this->pdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** @return array{failures: int, window_started_at: int, locked_until: int} */
    public function state(string $subjectHash): array
    {
        $statement = $this->pdo->prepare('SELECT failures, window_started_at, locked_until FROM admin_login_throttles WHERE subject_hash = :subject_hash');
        $statement->execute(['subject_hash' => $subjectHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['failures' => 0, 'window_started_at' => 0, 'locked_until' => 0];
        }
        return ['failures' => (int) $row['failures'], 'window_started_at' => (int) $row['window_started_at'], 'locked_until' => (int) $row['locked_until']];
    }

    public function save(string $subjectHash, int $failures, int $windowStartedAt, int $lockedUntil): void
    {
        $statement = $this->pdo->prepare('INSERT INTO admin_login_throttles (subject_hash, failures, window_started_at, locked_until) VALUES (:subject_hash, :failures, :window_started_at, :locked_until) ON CONFLICT(subject_hash) DO UPDATE SET failures = excluded.failures, window_started_at = excluded.window_started_at, locked_until = excluded.locked_until');
        $statement->execute(['subject_hash' => $subjectHash, 'failures' => $failures, 'window_started_at' => $windowStartedAt, 'locked_until' => $lockedUntil]);
    }

    public function clear(string $subjectHash): void
    {
        $statement = $this->pdo->prepare('DELETE FROM admin_login_throttles WHERE subject_hash = :subject_hash');
        $statement->execute(['subject_hash' => $subjectHash]);
    }
}
