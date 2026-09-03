<?php
// src/Application/AdminRequestLimits.php
// 管理面のリクエストと検索に適用する上限値を表す不変設定。

declare(strict_types=1);

namespace Relink\Resolver\Application;

use InvalidArgumentException;

final readonly class AdminRequestLimits
{
    public function __construct(
        public int $maxBodyBytes = 65536,
        public int $maxInputVars = 32,
        public int $maxPage = 10000,
        public int $defaultPerPage = 20,
        public int $maxPerPage = 50,
        public int $maxUuidBytes = 36,
        public int $maxLocationBytes = 2048,
        public int $maxEntityIdBytes = 2048,
        public int $maxMediaTypeBytes = 255,
        public int $maxIntegrityAlgorithmBytes = 64,
        public int $maxIntegrityDigestBytes = 128,
        public int $maxReasonBytes = 500,
        public int $maxSearchBytes = 200,
        public int $maxOtherFieldBytes = 2048,
    ) {
        if ($this->maxBodyBytes < 1 || $this->maxInputVars < 1 || $this->maxPage < 1 || $this->defaultPerPage < 1 || $this->maxPerPage < $this->defaultPerPage) {
            throw new InvalidArgumentException('管理リクエスト上限の設定が不正です。');
        }
    }
}
