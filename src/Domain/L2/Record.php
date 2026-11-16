<?php
// src/Domain/L2/Record.php
// Authority Record の wire payload 表現（spec resolver-l2-0.1-draft §9.5, §9.6）。
// wire envelope の strict parse と phase-1 wire canonicality は Stage 1 の WireStatement を
// 再利用する。ここでは §9.5 の payload メンバ集合、key entry、prevRecordDigest 形式、
// 埋め込み transfer trail の §12.2 形式を検証する。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class Record
{
    /** @var list<string> */
    private const REQUIRED_MEMBERS = [
        'algorithm',
        'anchorId',
        'entityId',
        'epochId',
        'expiresAt',
        'issuedAt',
        'keys',
        'l2Version',
        'prevRecordDigest',
        'recordGeneration',
        'signerKeyId',
        'statementFloor',
        'statementType',
    ];

    /**
     * @param array<string, mixed> $payload
     * @param list<KeyEntry> $keys
     * @param array<string, KeyEntry> $keyMap
     */
    private function __construct(
        private readonly array $payload,
        private readonly string $canonicalPayloadOctets,
        private readonly string $signatureBase64Url,
        private readonly string $signatureOctets,
        private readonly string $anchorId,
        private readonly string $entityId,
        private readonly string $algorithm,
        private readonly int $recordGeneration,
        private readonly string $epochId,
        private readonly int $statementFloor,
        private readonly int $issuedAt,
        private readonly int $expiresAt,
        private readonly string $signerKeyId,
        private readonly ?string $prevRecordDigest,
        private readonly array $keys,
        private readonly array $keyMap,
        private readonly ?TransferTrail $transfer,
    ) {
    }

    /**
     * wire text を phase-1 strict parse（WireStatement）に通し、§9.5 の Record payload を
     * 検証する。構造失敗は PayloadStructureException / WireStatementException（INVALID）。
     */
    public static function fromWire(string $wire): self
    {
        $statement = WireStatement::parse($wire);
        $payloadRaw = $statement->payload();

        /** @var array<string, mixed> $payload */
        $payload = $payloadRaw;

        $required = self::REQUIRED_MEMBERS;
        sort($required);
        $members = array_keys($payload);
        sort($members);
        $hasTransfer = array_key_exists('transfer', $payload);
        $allowed = $required;
        if ($hasTransfer) {
            $allowed[] = 'transfer';
            sort($allowed);
        }
        if ($members !== $allowed) {
            throw new PayloadStructureException('Record payload member set is not the §9.5 set: ' . implode(',', $members));
        }

        if ($payload['l2Version'] !== '0.1') {
            throw new PayloadStructureException('Record l2Version must be "0.1"');
        }
        if ($payload['statementType'] !== 'authority') {
            throw new PayloadStructureException('Record statementType must be "authority"');
        }

        foreach (['anchorId', 'entityId', 'algorithm', 'epochId', 'signerKeyId'] as $field) {
            if (!is_string($payload[$field]) || $payload[$field] === '') {
                throw new PayloadStructureException('Record ' . $field . ' must be a non-empty string');
            }
        }

        $recordGeneration = $payload['recordGeneration'];
        $statementFloor = $payload['statementFloor'];
        $issuedAt = $payload['issuedAt'];
        $expiresAt = $payload['expiresAt'];
        if (!is_int($recordGeneration) || $recordGeneration < 0) {
            throw new PayloadStructureException('recordGeneration must be a non-negative integer (§9.5)');
        }
        if (!is_int($statementFloor) || $statementFloor < 0) {
            throw new PayloadStructureException('statementFloor must be a non-negative integer (§9.5)');
        }
        if (!is_int($issuedAt) || $issuedAt < 0) {
            throw new PayloadStructureException('issuedAt must be a non-negative integer (§9.5)');
        }
        if (!is_int($expiresAt) || $expiresAt < 0) {
            throw new PayloadStructureException('expiresAt must be a non-negative integer (§9.5)');
        }
        if ($expiresAt <= $issuedAt) {
            throw new PayloadStructureException('Record expiresAt must be greater than issuedAt (§9.5)');
        }

        $prevRecordDigest = $payload['prevRecordDigest'];
        if ($recordGeneration === 0) {
            if ($hasTransfer) {
                throw new PayloadStructureException('transfer member is forbidden on Record 0 (§9.5)');
            }
            if ($prevRecordDigest !== null) {
                throw new PayloadStructureException('prevRecordDigest must be null for Record 0 (§9.5)');
            }
        } else {
            if (!is_string($prevRecordDigest) || preg_match('/^[0-9a-f]{64}$/', $prevRecordDigest) !== 1) {
                throw new PayloadStructureException('prevRecordDigest must be lowercase hex SHA-256 for Record G > 0 (§9.5)');
            }
        }

        $keysRaw = $payload['keys'];
        if (!is_array($keysRaw) || count($keysRaw) === 0) {
            throw new PayloadStructureException('Record keys must be a non-empty array of key entries (§9.5)');
        }

        /** @var list<mixed> $keyList */
        $keyList = $keysRaw;
        $entries = [];
        $keyMap = [];
        foreach ($keyList as $item) {
            if (!is_array($item)) {
                throw new PayloadStructureException('Record keys entries must be JSON objects (§9.5)');
            }
            /** @var array<string, mixed> $entry */
            $entry = $item;
            $keyEntry = KeyEntry::fromPayload($entry);
            if (array_key_exists($keyEntry->keyId(), $keyMap)) {
                throw new PayloadStructureException('Record keys array carries duplicate keyId ' . $keyEntry->keyId());
            }
            $entries[] = $keyEntry;
            $keyMap[$keyEntry->keyId()] = $keyEntry;
        }

        $transfer = null;
        if ($hasTransfer) {
            $transferMemberRaw = $payload['transfer'];
            if (!is_array($transferMemberRaw)) {
                throw new PayloadStructureException('transfer member must be a { payload, signature } object (§12.2)');
            }
            /** @var array<string, mixed> $transferMember */
            $transferMember = $transferMemberRaw;
            $transfer = TransferTrail::fromTransferMember($transferMember);
            // wire payload は JCS 正準（WireStatement が保証）。trail の正準化文字列が wire text に
            // 出現しない場合は fail-closed で拒否する（§12.2 wire canonicality）。
            $canonicalTrail = $transfer->canonicalPayloadOctets();
            if (!str_contains($wire, '"transfer":{' . '"payload":' . $canonicalTrail)) {
                throw new PayloadStructureException('embedded trail payload is not JCS canonical on the wire (§12.2)');
            }
        }

        return new self(
            $payload,
            $statement->canonicalPayloadOctets(),
            $statement->signatureBase64Url(),
            $statement->signatureOctets(),
            (string) $payload['anchorId'],
            (string) $payload['entityId'],
            (string) $payload['algorithm'],
            (int) $payload['recordGeneration'],
            (string) $payload['epochId'],
            (int) $payload['statementFloor'],
            (int) $payload['issuedAt'],
            (int) $payload['expiresAt'],
            (string) $payload['signerKeyId'],
            is_string($prevRecordDigest) ? $prevRecordDigest : null,
            $entries,
            $keyMap,
            $transfer,
        );
    }

    /** §9.5: SHA-256 lowercase hex of the UTF-8 octets of the JCS canonical payload. */
    public function payloadDigest(): string
    {
        return hash('sha256', $this->canonicalPayloadOctets);
    }

    /** @return list<KeyEntry> */
    public function keys(): array
    {
        return $this->keys;
    }

    public function key(string $keyId): ?KeyEntry
    {
        return $this->keyMap[$keyId] ?? null;
    }

    /** @return list<KeyEntry> */
    public function currentKeys(): array
    {
        return array_values(array_filter($this->keys, static fn (KeyEntry $entry): bool => $entry->status() === KeyStatus::CURRENT));
    }

    public function hasTransfer(): bool
    {
        return $this->transfer !== null;
    }

    public function transfer(): ?TransferTrail
    {
        return $this->transfer;
    }

    public function recordGeneration(): int
    {
        return $this->recordGeneration;
    }

    public function epochId(): string
    {
        return $this->epochId;
    }

    public function statementFloor(): int
    {
        return $this->statementFloor;
    }

    public function issuedAt(): int
    {
        return $this->issuedAt;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    public function signerKeyId(): string
    {
        return $this->signerKeyId;
    }

    public function prevRecordDigest(): ?string
    {
        return $this->prevRecordDigest;
    }

    public function anchorId(): string
    {
        return $this->anchorId;
    }

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function algorithm(): string
    {
        return $this->algorithm;
    }

    public function canonicalPayloadOctets(): string
    {
        return $this->canonicalPayloadOctets;
    }

    public function signatureBase64Url(): string
    {
        return $this->signatureBase64Url;
    }

    public function signatureOctets(): string
    {
        return $this->signatureOctets;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }
}
