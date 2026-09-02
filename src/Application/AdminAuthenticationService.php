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

    /** IP と既知アカウントの独立バケットを、原子的に評価して認証情報を検証する。 */
    public function attempt(string $clientAddress, string $username, string $password, int $now): string
    {
        $ipHash = hash('sha256', 'ip' . "\0" . $clientAddress);
        // 任意ユーザー名は保存キーに使わず、全IP共通lockによる管理面DoSも避ける。
        $subjectHashes = [$ipHash];
        $credentialsValid = strlen($username) <= 200 && strlen($password) <= 4096 && $this->credentialsMatch($username, $password);
        return $this->throttleStore->decide($subjectHashes, $credentialsValid, $now, $this->maxFailures, $this->failureWindowSeconds, $this->lockoutSeconds);
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
            && $session['authenticated_at'] <= $now
            && $session['last_activity_at'] <= $now
            && $session['authenticated_at'] <= $session['last_activity_at']
            && $now - $session['last_activity_at'] < $this->idleTimeoutSeconds
            && $now - $session['authenticated_at'] < $this->absoluteTimeoutSeconds;
    }

    /** パスワードハッシュ設定と従来の固定シークレット設定の両方を安全に比較する。 */
    private function credentialsMatch(string $username, string $password): bool
    {
        if ($username !== $this->username || $this->password === '') {
            return false;
        }
        if (password_get_info($this->password)['algo'] !== null) {
            return password_verify($password, $this->password);
        }
        return hash_equals($this->password, $password);
    }
}
