<?php
// src/Application/AdminSessionPolicy.php
// 管理セッションの時刻有効性だけを判定するフレームワーク非依存ポリシー。

declare(strict_types=1);

namespace Relink\Resolver\Application;

final readonly class AdminSessionPolicy
{
    public function __construct(
        private int $idleTimeoutSeconds,
        private int $absoluteTimeoutSeconds,
    ) {
    }

    /** セッション値が時刻制約とアイドル期限を満たすか判定する。identity認可はホストが行う。 */
    public function isValid(?AdminSession $session, int $now): bool
    {
        if ($session === null) {
            return false;
        }
        return $session->authenticatedAt <= $now
            && $session->lastActivityAt <= $now
            && $session->authenticatedAt <= $session->lastActivityAt
            && $now - $session->lastActivityAt < $this->idleTimeoutSeconds
            && $now - $session->authenticatedAt < $this->absoluteTimeoutSeconds;
    }
}
