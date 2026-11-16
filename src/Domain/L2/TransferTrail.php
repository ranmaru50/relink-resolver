<?php
// src/Domain/L2/TransferTrail.php
// 埋め込み transfer trail の payload と signature（spec resolver-l2-0.1-draft §12.2）。
// trail payload は §12.2 のメンバ集合を厳密に持ち、keyId/generation/issuedAt/expiresAt/
// descriptionLocation/lifecycleStatus/effectiveGeneration を持たない。trail は有効期間を持たない。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class TransferTrail
{
    /** @var list<string> */
    private const REQUIRED_MEMBERS = [
        'algorithm',
        'anchorId',
        'entityId',
        'epochId',
        'incomingKeyId',
        'l2Version',
        'newEpochId',
        'outgoingKeyId',
        'recordGeneration',
        'statementFloor',
        'statementType',
    ];

    /** @var list<string> */
    private const FORBIDDEN_MEMBERS = [
        'keyId',
        'generation',
        'issuedAt',
        'expiresAt',
        'descriptionLocation',
        'lifecycleStatus',
        'effectiveGeneration',
        'signature',
    ];

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        private readonly array $payload,
        private readonly string $canonicalPayloadOctets,
        private readonly string $signatureBase64Url,
        private readonly string $signatureOctets,
        private readonly string $anchorId,
        private readonly string $entityId,
        private readonly string $algorithm,
        private readonly string $epochId,
        private readonly string $newEpochId,
        private readonly string $outgoingKeyId,
        private readonly string $incomingKeyId,
        private readonly int $recordGeneration,
        private readonly int $statementFloor,
    ) {
    }

    /**
     * Record payload の transfer メンバ { payload, signature } を §12.2 形式で検証する。
     *
     * @param array<string, mixed> $transferMember
     */
    public static function fromTransferMember(array $transferMember): self
    {
        $members = array_keys($transferMember);
        sort($members);
        if ($members !== ['payload', 'signature']) {
            throw new PayloadStructureException('transfer member must carry exactly payload and signature');
        }

        $payloadRaw = $transferMember['payload'] ?? null;
        $signatureRaw = $transferMember['signature'] ?? null;
        if (!is_array($payloadRaw)) {
            throw new PayloadStructureException('transfer payload must be a JSON object');
        }
        if (!is_string($signatureRaw)) {
            throw new PayloadStructureException('transfer signature must be a JSON string');
        }

        /** @var array<string, mixed> $payload */
        $payload = $payloadRaw;
        self::validateTrailPayload($payload);

        $canonical = JcsCanonicalizer::canonicalize($payload);
        if (!Base64Url::isValid($signatureRaw)) {
            throw new PayloadStructureException('transfer signature must be base64url (RFC 4648 §5, no padding)');
        }
        $signatureOctets = Base64Url::decode($signatureRaw);
        if (strlen($signatureOctets) !== 64) {
            throw new PayloadStructureException('transfer signature must be 64 octets for ed25519 (§10.8)');
        }

        return new self(
            $payload,
            $canonical,
            $signatureRaw,
            $signatureOctets,
            (string) $payload['anchorId'],
            (string) $payload['entityId'],
            (string) $payload['algorithm'],
            (string) $payload['epochId'],
            (string) $payload['newEpochId'],
            (string) $payload['outgoingKeyId'],
            (string) $payload['incomingKeyId'],
            (int) $payload['recordGeneration'],
            (int) $payload['statementFloor'],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function validateTrailPayload(array $payload): void
    {
        foreach (self::FORBIDDEN_MEMBERS as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                throw new PayloadStructureException('trail payload carries forbidden member ' . $forbidden . ' (§12.2)');
            }
        }

        $members = array_keys($payload);
        sort($members);
        $required = self::REQUIRED_MEMBERS;
        sort($required);
        if ($members !== $required) {
            throw new PayloadStructureException('trail payload member set is not the §12.2 set');
        }

        if ($payload['l2Version'] !== '0.1') {
            throw new PayloadStructureException('trail l2Version must be "0.1"');
        }
        if ($payload['statementType'] !== 'transfer') {
            throw new PayloadStructureException('trail statementType must be "transfer"');
        }
        foreach (['anchorId', 'entityId', 'algorithm', 'epochId', 'newEpochId', 'outgoingKeyId', 'incomingKeyId'] as $field) {
            if (!is_string($payload[$field]) || $payload[$field] === '') {
                throw new PayloadStructureException('trail ' . $field . ' must be a non-empty string');
            }
        }
        $recordGeneration = $payload['recordGeneration'];
        $statementFloor = $payload['statementFloor'];
        if (!is_int($recordGeneration) || $recordGeneration < 0) {
            throw new PayloadStructureException('trail recordGeneration must be a non-negative integer');
        }
        if (!is_int($statementFloor) || $statementFloor < 0) {
            throw new PayloadStructureException('trail statementFloor must be a non-negative integer');
        }
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
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

    public function epochId(): string
    {
        return $this->epochId;
    }

    public function newEpochId(): string
    {
        return $this->newEpochId;
    }

    public function outgoingKeyId(): string
    {
        return $this->outgoingKeyId;
    }

    public function incomingKeyId(): string
    {
        return $this->incomingKeyId;
    }

    public function recordGeneration(): int
    {
        return $this->recordGeneration;
    }

    public function statementFloor(): int
    {
        return $this->statementFloor;
    }
}
