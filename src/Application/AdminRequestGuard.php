<?php
// src/Application/AdminRequestGuard.php
// 管理面に到達したHTTP入力のサイズ・形式・ページングを検査する。

declare(strict_types=1);

namespace Relink\Resolver\Application;

final class AdminRequestGuard
{
    /** @var array<string, int> 管理POSTで許可する既知フィールドの最大バイト数。 */
    private array $postFieldLimits;

    /** @var array<string, int> 管理GETで許可する既知フィールドの最大バイト数。 */
    private array $queryFieldLimits;

    public function __construct(private readonly AdminRequestLimits $limits)
    {
        $this->postFieldLimits = [
            'action' => 32,
            'csrf' => 128,
            'username' => 128,
            'password' => 1024,
            'uuid' => $limits->maxUuidBytes,
            'location' => $limits->maxLocationBytes,
            'entity_id' => $limits->maxEntityIdBytes,
            'state' => 16,
            'media_type' => $limits->maxMediaTypeBytes,
            'integrity_algorithm' => $limits->maxIntegrityAlgorithmBytes,
            'integrity_digest' => $limits->maxIntegrityDigestBytes,
            'reason' => $limits->maxReasonBytes,
        ];
        $this->queryFieldLimits = [
            'format' => 16,
            'uuid' => $limits->maxUuidBytes,
            'q' => $limits->maxSearchBytes,
            'page' => 8,
            'per_page' => 8,
        ];
    }

    /** Content-Lengthを検査し、Webサーバー設定との不一致を安全な413へ分類する。 */
    public function assertContentLength(?string $contentLength): void
    {
        if ($contentLength === null || $contentLength === '') {
            return;
        }
        if (!preg_match('/^[0-9]+$/D', $contentLength)) {
            throw new AdminRequestException(400);
        }
        if ((int) $contentLength > $this->limits->maxBodyBytes) {
            throw new AdminRequestException(413);
        }
    }

    /** PHPパーサーの切り詰め前に取得したraw入力の変数数を検査する。 */
    public function assertRawVariableCount(string $rawInput): void
    {
        if ($rawInput === '') {
            return;
        }
        if (count(explode('&', $rawInput)) > $this->limits->maxInputVars) {
            throw new AdminRequestException(400);
        }
    }

    /** 管理変更操作でPHPが自動解釈できるフォーム形式だけを許可する。 */
    public function assertContentType(string $method, ?string $contentType): void
    {
        if (strtoupper($method) !== 'POST' || $contentType === null || $contentType === '') {
            return;
        }
        if (!preg_match('/^application\/x-www-form-urlencoded(?:\s*;|$)/i', trim($contentType))) {
            throw new AdminRequestException(400);
        }
    }

    /**
     * POSTの配列化された値を検査し、配列値や未知の巨大値を拒否する。
     *
     * @param array<string, mixed> $input
     */
    public function assertPost(array $input): void
    {
        if (count($input) > $this->limits->maxInputVars) {
            throw new AdminRequestException(400);
        }
        $this->assertFields($input, $this->postFieldLimits);
    }

    /**
     * 管理GETの値を検査し、巨大な検索語や配列パラメータを拒否する。
     *
     * @param array<string, mixed> $query
     */
    public function assertQuery(array $query): void
    {
        $this->assertFields($query, $this->queryFieldLimits);
    }

    /**
     * 検索語とページング値を正規化して、上限内の値オブジェクトに変換する。
     *
     * @param array<string, mixed> $query
     */
    public function listQuery(array $query): AdminListQuery
    {
        $this->assertQuery($query);
        $needle = $this->scalar($query['q'] ?? '');
        $page = $this->positiveInteger($query['page'] ?? '1', $this->limits->maxPage);
        $perPage = $this->positiveInteger($query['per_page'] ?? (string) $this->limits->defaultPerPage, $this->limits->maxPerPage);

        return new AdminListQuery(strtolower(trim($needle)), $page, $perPage);
    }

    /**
     * 各フィールドの型とバイト上限をまとめて検査する。
     *
     * @param array<string, mixed> $fields
     * @param array<string, int> $knownLimits
     */
    private function assertFields(array $fields, array $knownLimits): void
    {
        foreach ($fields as $name => $value) {
            if (!is_scalar($value) || is_bool($value)) {
                throw new AdminRequestException(400);
            }
            $value = (string) $value;
            $limit = $knownLimits[$name] ?? $this->limits->maxOtherFieldBytes;
            if (strlen($value) > $limit) {
                throw new AdminRequestException(400);
            }
        }
    }

    /** @param mixed $value */
    private function scalar(mixed $value): string
    {
        return (string) $value;
    }

    /** 先頭ゼロや符号を許さず、ページ番号の上限を適用する。 */
    /** @param mixed $value */
    private function positiveInteger(mixed $value, int $maximum): int
    {
        if (!is_scalar($value) || is_bool($value) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)) {
            throw new AdminRequestException(400);
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximum]]);
        if ($number === false) {
            throw new AdminRequestException(400);
        }
        return $number;
    }
}
