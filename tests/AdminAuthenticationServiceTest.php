<?php
// tests/AdminAuthenticationServiceTest.php
// 管理画面のログイン乱用対策とセッション期限を検証する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Application\AdminAuthenticationService;
use Relink\Resolver\Adapters\SqliteAdminLoginThrottleStore;
use Relink\Resolver\Adapters\SqliteMigrator;
use Relink\Resolver\Ports\AdminLoginThrottleStore;

final class AdminAuthenticationServiceTest extends TestCase
{
    /** 制限回数に到達した認証試行は、正しい認証情報でも受理しない。 */
    public function testLockedSubjectCannotLogInUntilLockoutExpires(): void
    {
        $store = new InMemoryAdminLoginThrottleStore();
        $service = new AdminAuthenticationService($store, 'admin', 'secret', 2, 60, 120, 300, 3_600);

        self::assertSame('rejected_invalid', $service->attempt('192.0.2.1', 'admin', 'wrong', 1_000));
        self::assertSame('rejected_invalid', $service->attempt('192.0.2.1', 'admin', 'wrong', 1_001));
        self::assertSame('rejected_locked', $service->attempt('192.0.2.1', 'admin', 'secret', 1_002));
        self::assertSame('accepted', $service->attempt('192.0.2.1', 'admin', 'secret', 1_122));
    }

    /** IP lockは別IPの正規管理者を妨げず、username変更で同一IPの制限を回避できない。 */
    public function testLoginThrottleUsesOnlyIpBucket(): void
    {
        $store = new InMemoryAdminLoginThrottleStore();
        $service = new AdminAuthenticationService($store, 'admin', 'secret', 1, 60, 120, 300, 3_600);

        self::assertSame('rejected_invalid', $service->attempt('192.0.2.1', 'admin', 'wrong', 1_000));
        self::assertSame('accepted', $service->attempt('192.0.2.2', 'admin', 'secret', 1_001));
        self::assertSame('rejected_locked', $service->attempt('192.0.2.1', 'other', 'wrong', 1_001));
    }

    /** アイドル期限または絶対期限を超えたセッションは無効である。 */
    public function testSessionExpiresAtIdleAndAbsoluteLimits(): void
    {
        $service = new AdminAuthenticationService(new InMemoryAdminLoginThrottleStore(), 'admin', 'secret', 5, 60, 120, 300, 3_600);

        self::assertTrue($service->isSessionValid(['admin' => 'admin', 'authenticated_at' => 1_000, 'last_activity_at' => 1_200], 1_499));
        self::assertFalse($service->isSessionValid(['admin' => 'admin', 'authenticated_at' => 1_000, 'last_activity_at' => 1_200], 1_500));
        self::assertFalse($service->isSessionValid(['admin' => 'admin', 'authenticated_at' => 1_000, 'last_activity_at' => 1_200], 4_600));
    }

    /** 実SQLiteアダプタでも失敗累積、lock、期限後の回復、期限切れ行purgeを確認する。 */
    public function testSqliteThrottlePersistsLockAndPurgesExpiredState(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'relink-auth-');
        self::assertNotFalse($path);
        try {
            SqliteMigrator::migrate($path);
            $service = new AdminAuthenticationService(new SqliteAdminLoginThrottleStore($path), 'admin', 'secret', 2, 10, 20, 300, 3600);
            self::assertSame('rejected_invalid', $service->attempt('192.0.2.1', 'admin', 'wrong', 100));
            self::assertSame('rejected_invalid', $service->attempt('192.0.2.1', 'admin', 'wrong', 101));
            self::assertSame('rejected_locked', $service->attempt('192.0.2.1', 'admin', 'secret', 102));
            self::assertSame('accepted', $service->attempt('192.0.2.1', 'admin', 'secret', 121));
            $pdo = new PDO('sqlite:' . $path);
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM admin_login_throttles')->fetchColumn());
        } finally {
            @unlink($path);
        }
    }

    /** 独立したSQLite接続でも同一IPの更新を累積し、上限cleanup後にcurrent IPを維持する。 */
    public function testSqliteThrottleAccumulatesAcrossConnectionsAndBoundsRows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'relink-auth-');
        self::assertNotFalse($path);
        try {
            SqliteMigrator::migrate($path);
            $first = new AdminAuthenticationService(new SqliteAdminLoginThrottleStore($path), 'admin', 'secret', 3, 100, 100, 300, 3600);
            $second = new AdminAuthenticationService(new SqliteAdminLoginThrottleStore($path), 'admin', 'secret', 3, 100, 100, 300, 3600);
            self::assertSame('rejected_invalid', $first->attempt('192.0.2.9', 'admin', 'wrong', 100));
            self::assertSame('rejected_invalid', $second->attempt('192.0.2.9', 'admin', 'wrong', 101));
            self::assertSame('rejected_invalid', $first->attempt('192.0.2.9', 'admin', 'wrong', 102));
            self::assertSame('rejected_locked', $second->attempt('192.0.2.9', 'admin', 'secret', 103));

            $pdo = new PDO('sqlite:' . $path);
            $insert = $pdo->prepare('INSERT INTO admin_login_throttles (subject_hash, failures, window_started_at, locked_until) VALUES (?, 1, 1000, 0)');
            for ($i = 0; $i < 10000; $i++) {
                $insert->execute([hash('sha256', 'fixture-' . $i)]);
            }
            self::assertSame('rejected_invalid', $first->attempt('198.51.100.7', 'admin', 'wrong', 1000));
            self::assertLessThanOrEqual(10000, (int) $pdo->query('SELECT COUNT(*) FROM admin_login_throttles')->fetchColumn());
            $hash = hash('sha256', "ip\0" . '198.51.100.7');
            $statement = $pdo->prepare('SELECT COUNT(*) FROM admin_login_throttles WHERE subject_hash = ?');
            $statement->execute([$hash]);
            self::assertSame(1, (int) $statement->fetchColumn());
        } finally {
            @unlink($path);
        }
    }
}

/** テスト用にログイン制限状態を保持する。 */
final class InMemoryAdminLoginThrottleStore implements AdminLoginThrottleStore
{
    /** @var array<string, array{failures: int, window_started_at: int, locked_until: int}> */
    private array $states = [];

    public function decide(array $subjectHashes, bool $credentialsValid, int $now, int $maxFailures, int $failureWindowSeconds, int $lockoutSeconds): string
    {
        foreach ($subjectHashes as $subjectHash) {
            $state = $this->states[$subjectHash] ?? ['failures' => 0, 'window_started_at' => 0, 'locked_until' => 0];
            if ($state['locked_until'] > $now) {
                return 'rejected_locked';
            }
        }
        if ($credentialsValid) {
            foreach ($subjectHashes as $subjectHash) {
                unset($this->states[$subjectHash]);
            }
            return 'accepted';
        }
        foreach ($subjectHashes as $subjectHash) {
            $state = $this->states[$subjectHash] ?? ['failures' => 0, 'window_started_at' => 0, 'locked_until' => 0];
            $withinWindow = $state['window_started_at'] > 0 && $now - $state['window_started_at'] < $failureWindowSeconds;
            $failures = $withinWindow ? $state['failures'] + 1 : 1;
            $this->states[$subjectHash] = ['failures' => $failures, 'window_started_at' => $withinWindow ? $state['window_started_at'] : $now, 'locked_until' => $failures >= $maxFailures ? $now + $lockoutSeconds : 0];
        }
        return 'rejected_invalid';
    }
}
