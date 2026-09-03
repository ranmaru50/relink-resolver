<?php
// src/Application/AdminListQuery.php
// 管理一覧の正規化済みページング条件を表す不変値。

declare(strict_types=1);

namespace Relink\Resolver\Application;

final readonly class AdminListQuery
{
    public function __construct(
        public string $needle,
        public int $page,
        public int $perPage,
    ) {
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
