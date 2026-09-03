<?php
// src/Application/AdminAuthenticationService.php
// 管理ログインの試行制限を判定するフレームワーク非依存アプリケーションサービス。

declare(strict_types=1);

namespace Relink\Resolver\Application;

use Relink\Resolver\Ports\AdminLoginThrottleStore;
use Relink\Resolver\Ports\AdminCredentialVerifier;

final class AdminAuthenticationService
{
    public function __construct(
        private readonly AdminLoginThrottleStore $throttleStore,
        private readonly AdminCredentialVerifier $credentialVerifier,
        private readonly int $maxFailures,
        private readonly int $failureWindowSeconds,
        private readonly int $lockoutSeconds,
    ) {
    }

    /** IP単位バケットを原子的に評価して認証情報を検証する。 */
    public function attempt(string $clientAddress, string $username, string $password, int $now): string
    {
        $ipHash = hash('sha256', 'ip' . "\0" . $clientAddress);
        // 任意ユーザー名は保存キーに使わず、全IP共通lockによる管理面DoSも避ける。
        $subjectHashes = [$ipHash];
        $credentialsValid = strlen($username) <= 200 && strlen($password) <= 4096 && $this->credentialVerifier->verify($username, $password);
        return $this->throttleStore->decide($subjectHashes, $credentialsValid, $now, $this->maxFailures, $this->failureWindowSeconds, $this->lockoutSeconds);
    }
}
