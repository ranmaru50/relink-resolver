<?php
// src/Application/AdminAuthenticationService.php
// 管理ログインの試行制限とブラウザーセッション期限を判定するアプリケーションサービス。

declare(strict_types=1);

namespace Relink\Resolver\Application;

use Relink\Resolver\Ports\AdminLoginThrottleStore;

final class AdminAuthenticationService
{
    public function __construct(
        private readonly AdminLoginThrottleStore $throttleStore,
        private readonly string $username,
        private readonly string $password,
        private readonly int $maxFailures,
        private readonly int $failureWindowSeconds,
        private readonly int $lockoutSeconds,
        private readonly int $idleTimeoutSeconds,
        private readonly int $absoluteTimeoutSeconds,
    ) {
    }

    /** IP とユーザー名ごとの制限を適用して認証情報を検証する。 */
    public function attempt(string $clientAddress, string $username, string $password, int $now): bool
    {
        $subjectHash = hash('sha256', $clientAddress . "\0" . $username);
        $state = $this->throttleStore->state($subjectHash);
        if ($state['locked_until'] > $now) {
            return false;
        }

        if ($this->credentialsMatch($username, $password)) {
            $this->throttleStore->clear($subjectHash);
            return true;
        }

        $withinWindow = $state['window_started_at'] > 0 && $now - $state['window_started_at'] < $this->failureWindowSeconds;
        $failures = $withinWindow ? $state['failures'] + 1 : 1;
        $windowStartedAt = $withinWindow ? $state['window_started_at'] : $now;
        $lockedUntil = $failures >= $this->maxFailures ? $now + $this->lockoutSeconds : 0;
        $this->throttleStore->save($subjectHash, $failures, $windowStartedAt, $lockedUntil);
        return false;
    }

    /** @param array<string, mixed> $session */
    public function isSessionValid(array $session, int $now): bool
    {
        if (!isset($session['admin'], $session['authenticated_at'], $session['last_activity_at'])) {
            return false;
        }
        return is_string($session['admin'])
            && $session['admin'] === $this->username
            && is_int($session['authenticated_at'])
            && is_int($session['last_activity_at'])
            && $now - $session['last_activity_at'] < $this->idleTimeoutSeconds
            && $now - $session['authenticated_at'] < $this->absoluteTimeoutSeconds;
    }

    /** パスワードハッシュ設定と従来の固定シークレット設定の両方を安全に比較する。 */
    private function credentialsMatch(string $username, string $password): bool
    {
        if ($username !== $this->username || $this->password === '') {
            return false;
        }
        if (str_starts_with($this->password, '$')) {
            return password_verify($password, $this->password);
        }
        return hash_equals($this->password, $password);
    }
}
