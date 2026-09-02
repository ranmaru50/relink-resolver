<?php
// src/Ports/AdminLoginThrottleStore.php
// 管理ログイン試行の制限状態を保持するためのポート。

declare(strict_types=1);

namespace Relink\Resolver\Ports;

interface AdminLoginThrottleStore
{
    /**
     * 複数の制限バケットを一つの永続化境界で評価・更新する。
     *
     * @param non-empty-list<string> $subjectHashes
     * @return 'accepted'|'rejected_locked'|'rejected_invalid'
     */
    public function decide(array $subjectHashes, bool $credentialsValid, int $now, int $maxFailures, int $failureWindowSeconds, int $lockoutSeconds): string;
}
