<?php
// tests/AdminAuthenticationServiceTest.php
// 管理画面のログイン乱用対策とセッション期限を検証する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Application\AdminAuthenticationService;
use Relink\Resolver\Ports\AdminLoginThrottleStore;

final class AdminAuthenticationServiceTest extends TestCase
{
    /** 制限回数に到達した認証試行は、正しい認証情報でも受理しない。 */
    public function testLockedSubjectCannotLogInUntilLockoutExpires(): void
    {
        $store = new InMemoryAdminLoginThrottleStore();
        $service = new AdminAuthenticationService($store, 'admin', 'secret', 2, 60, 120, 300, 3_600);

        self::assertFalse($service->attempt('192.0.2.1', 'admin', 'wrong', 1_000));
        self::assertFalse($service->attempt('192.0.2.1', 'admin', 'wrong', 1_001));
        self::assertFalse($service->attempt('192.0.2.1', 'admin', 'secret', 1_002));
        self::assertTrue($service->attempt('192.0.2.1', 'admin', 'secret', 1_122));
    }

    /** 異なる送信元またはユーザー名は個別に失敗回数を数える。 */
    public function testLoginThrottleIsScopedToClientAndUsername(): void
    {
        $store = new InMemoryAdminLoginThrottleStore();
        $service = new AdminAuthenticationService($store, 'admin', 'secret', 1, 60, 120, 300, 3_600);

        self::assertFalse($service->attempt('192.0.2.1', 'admin', 'wrong', 1_000));
        self::assertTrue($service->attempt('192.0.2.2', 'admin', 'secret', 1_001));
        self::assertFalse($service->attempt('192.0.2.1', 'other', 'wrong', 1_001));
    }

    /** アイドル期限または絶対期限を超えたセッションは無効である。 */
    public function testSessionExpiresAtIdleAndAbsoluteLimits(): void
    {
        $service = new AdminAuthenticationService(new InMemoryAdminLoginThrottleStore(), 'admin', 'secret', 5, 60, 120, 300, 3_600);

        self::assertTrue($service->isSessionValid(['admin' => 'admin', 'authenticated_at' => 1_000, 'last_activity_at' => 1_200], 1_499));
        self::assertFalse($service->isSessionValid(['admin' => 'admin', 'authenticated_at' => 1_000, 'last_activity_at' => 1_200], 1_500));
        self::assertFalse($service->isSessionValid(['admin' => 'admin', 'authenticated_at' => 1_000, 'last_activity_at' => 1_200], 4_600));
    }
}

/** テスト用にログイン制限状態を保持する。 */
final class InMemoryAdminLoginThrottleStore implements AdminLoginThrottleStore
{
    /** @var array<string, array{failures: int, window_started_at: int, locked_until: int}> */
    private array $states = [];

    public function state(string $subjectHash): array
    {
        return $this->states[$subjectHash] ?? ['failures' => 0, 'window_started_at' => 0, 'locked_until' => 0];
    }

    public function save(string $subjectHash, int $failures, int $windowStartedAt, int $lockedUntil): void
    {
        $this->states[$subjectHash] = ['failures' => $failures, 'window_started_at' => $windowStartedAt, 'locked_until' => $lockedUntil];
    }

    public function clear(string $subjectHash): void
    {
        unset($this->states[$subjectHash]);
    }
}
