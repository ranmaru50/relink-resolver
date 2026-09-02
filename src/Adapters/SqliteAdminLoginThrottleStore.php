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

    /** @param non-empty-list<string> $subjectHashes */
    public function decide(array $subjectHashes, bool $credentialsValid, int $now, int $maxFailures, int $failureWindowSeconds, int $lockoutSeconds): string
    {
        // BEGIN IMMEDIATE により、同一DBの認証試行を一つずつ直列化する。
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->purgeAndBound($now, $failureWindowSeconds, $subjectHashes);
            $states = [];
            foreach ($subjectHashes as $subjectHash) {
                $states[$subjectHash] = $this->state($subjectHash);
            }
            foreach ($states as $state) {
                if ($state['locked_until'] > $now) {
                    $this->pdo->exec('COMMIT');
                    return 'rejected_locked';
                }
            }
            if ($credentialsValid) {
                foreach ($subjectHashes as $subjectHash) {
                    $this->clear($subjectHash);
                }
                $this->pdo->exec('COMMIT');
                return 'accepted';
            }
            foreach ($states as $subjectHash => $state) {
                $withinWindow = $state['window_started_at'] > 0 && $now - $state['window_started_at'] < $failureWindowSeconds;
                $failures = $withinWindow ? $state['failures'] + 1 : 1;
                $this->save($subjectHash, $failures, $withinWindow ? $state['window_started_at'] : $now, $failures >= $maxFailures ? $now + $lockoutSeconds : 0);
            }
            $this->pdo->exec('COMMIT');
            return 'rejected_invalid';
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } else {
                $this->pdo->exec('ROLLBACK');
            }
            throw $error;
        }
    }

    /** @return array{failures: int, window_started_at: int, locked_until: int} */
    private function state(string $subjectHash): array
    {
        $statement = $this->pdo->prepare('SELECT failures, window_started_at, locked_until FROM admin_login_throttles WHERE subject_hash = :subject_hash');
        $statement->execute(['subject_hash' => $subjectHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? ['failures' => 0, 'window_started_at' => 0, 'locked_until' => 0] : ['failures' => (int) $row['failures'], 'window_started_at' => (int) $row['window_started_at'], 'locked_until' => (int) $row['locked_until']];
    }

    private function save(string $subjectHash, int $failures, int $windowStartedAt, int $lockedUntil): void
    {
        $statement = $this->pdo->prepare('INSERT INTO admin_login_throttles (subject_hash, failures, window_started_at, locked_until) VALUES (:subject_hash, :failures, :window_started_at, :locked_until) ON CONFLICT(subject_hash) DO UPDATE SET failures = excluded.failures, window_started_at = excluded.window_started_at, locked_until = excluded.locked_until');
        $statement->execute(['subject_hash' => $subjectHash, 'failures' => $failures, 'window_started_at' => $windowStartedAt, 'locked_until' => $lockedUntil]);
    }

    private function clear(string $subjectHash): void
    {
        $statement = $this->pdo->prepare('DELETE FROM admin_login_throttles WHERE subject_hash = :subject_hash');
        $statement->execute(['subject_hash' => $subjectHash]);
    }

    /** @param list<string> $protectedHashes */
    private function purgeAndBound(int $now, int $failureWindowSeconds, array $protectedHashes): void
    {
        $expiry = $now - $failureWindowSeconds;
        $this->pdo->prepare('DELETE FROM admin_login_throttles WHERE locked_until <= :now AND window_started_at < :expiry')->execute(['now' => $now, 'expiry' => $expiry]);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM admin_login_throttles')->fetchColumn();
        if ($count < 10000) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($protectedHashes), '?'));
        $statement = $this->pdo->prepare("DELETE FROM admin_login_throttles WHERE subject_hash IN (SELECT subject_hash FROM admin_login_throttles WHERE subject_hash NOT IN ($placeholders) ORDER BY window_started_at ASC LIMIT 1000)");
        $statement->execute($protectedHashes);
    }
}
