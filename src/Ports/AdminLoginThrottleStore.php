<?php
// src/Ports/AdminLoginThrottleStore.php
// 管理ログイン試行の制限状態を保持するためのポート。

declare(strict_types=1);

namespace Relink\Resolver\Ports;

interface AdminLoginThrottleStore
{
    /** @return array{failures: int, window_started_at: int, locked_until: int} */
    public function state(string $subjectHash): array;

    public function save(string $subjectHash, int $failures, int $windowStartedAt, int $lockedUntil): void;

    public function clear(string $subjectHash): void;
}
