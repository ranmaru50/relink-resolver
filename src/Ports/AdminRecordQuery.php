<?php
// src/Ports/AdminRecordQuery.php
// 管理面の一覧検索だけを担う読み取りポート。

declare(strict_types=1);

namespace Relink\Resolver\Ports;

use Relink\Resolver\Domain\ResolverRecord;

interface AdminRecordQuery
{
    /** @return list<ResolverRecord> */
    public function search(string $needle, int $limit, int $offset): array;
}
