<?php
// src/Application/AdminSession.php
// ホストのセッション保存形式とアプリケーションの間をつなぐ不変セッション値。

declare(strict_types=1);

namespace Relink\Resolver\Application;

final readonly class AdminSession
{
    public function __construct(
        public string $admin,
        public int $authenticatedAt,
        public int $lastActivityAt,
    ) {
    }

    /**
     * ホストが管理するセッション値を型付き値へ変換する。
     *
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): ?self
    {
        if (!isset($values['admin'], $values['authenticated_at'], $values['last_activity_at'])) {
            return null;
        }
        if (!is_string($values['admin']) || !is_int($values['authenticated_at']) || !is_int($values['last_activity_at'])) {
            return null;
        }
        return new self($values['admin'], $values['authenticated_at'], $values['last_activity_at']);
    }
}
