<?php
// src/Domain/FetchedRepresentation.php
// 管理 outbound fetch が返す最終 HTTP 表現の取得メタデータ。

declare(strict_types=1);

namespace Relink\Resolver\Domain;

final readonly class FetchedRepresentation
{
    /** @param list<string> $redirectChain */
    public function __construct(
        public string $finalUrl,
        public int $status,
        public string $body,
        public array $redirectChain = [],
    ) {
    }
}
