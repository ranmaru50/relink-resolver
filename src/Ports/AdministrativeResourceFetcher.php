<?php
// src/Ports/AdministrativeResourceFetcher.php
// 管理操作だけが利用する外部リソース取得 Port。

declare(strict_types=1);

namespace Relink\Resolver\Ports;

use Relink\Resolver\Domain\FetchedRepresentation;
use Relink\Resolver\Domain\DescriptionLocation;

interface AdministrativeResourceFetcher
{
    /**
     * HTTPS リダイレクトとネットワークポリシーを適用して最終表現を取得する。
     * 実装は body を content-coding 処理後の octets として返す。
     */
    public function fetch(DescriptionLocation $location): FetchedRepresentation;
}
