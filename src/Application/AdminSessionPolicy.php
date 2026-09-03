<?php
// src/Application/AdminSessionPolicy.php
// 管理セッションの有効期限を判定するフレームワーク非依存ポリシー。

declare(strict_types=1);

namespace Relink\Resolver\Application;

final readonly class AdminSessionPolicy
{
    public function __construct(
        private string $adminUsername,
        private int $idleTimeoutSeconds,
        private int $absoluteTimeoutSeconds,
    ) {
    }

    /** セッション値が対象管理者、時刻制約、アイドル期限を満たすか判定する。 */
    public function isValid(?AdminSession $session, int $now): bool
    {
        if ($session === null || $session->admin !== $this->adminUsername) {
            return false;
        }
        return $session->authenticatedAt <= $now
            && $session->lastActivityAt <= $now
            && $session->authenticatedAt <= $session->lastActivityAt
            && $now - $session->lastActivityAt < $this->idleTimeoutSeconds
            && $now - $session->authenticatedAt < $this->absoluteTimeoutSeconds;
    }
}
