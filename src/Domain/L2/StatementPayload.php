<?php
// src/Domain/L2/StatementPayload.php
// L2 resolution statement payload model（spec resolver-l2-0.1-draft §7.2, §7.3）。
// wire canonicality・duplicate member・整数範囲・payload/signature 分離は Stage 1 の
// WireStatement が phase 1 で保証する。Statement payload は必須 member と型を検証し、
// 未知の extension member は許容する（§7.2、L2-VER-046）。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class StatementPayload
{
    /** @var list<string> §7.2 required statement payload members. */
    private const REQUIRED_MEMBERS = [
        'l2Version',
        'statementType',
        'anchorId',
        'entityId',
        'descriptionLocation',
        'lifecycleStatus',
        'keyId',
        'algorithm',
        'issuedAt',
        'expiresAt',
        'generation',
        'epochId',
    ];

    /** @var list<string> §7.2 lines 298-299: the enumerated lifecycleStatus values. */
    private const LIFECYCLE_STATUSES = ['active', 'suspended', 'retired'];

    private function __construct(
        /** @var array<string, mixed> */
        private readonly array $payload,
        private readonly string $canonicalPayloadOctets,
        private readonly string $signatureBase64Url,
        private readonly string $signatureOctets,
        private readonly string $anchorId,
        private readonly string $entityId,
        private readonly string $descriptionLocation,
        private readonly string $lifecycleStatus,
        private readonly string $keyId,
        private readonly string $algorithm,
        private readonly string $epochId,
        private readonly int $issuedAt,
        private readonly int $expiresAt,
        private readonly int $generation,
    ) {
    }

    /**
     * @throws WireStatementException §7.3 wire canonicality and strict parse failures (§15.2 phase 1)
     * @throws PayloadStructureException required members, types, values, and window ordering (§7.2)
     */
    public static function fromWire(string $wire): self
    {
        $statement = WireStatement::parse($wire);
        $payload = $statement->payload();

        foreach (self::REQUIRED_MEMBERS as $member) {
            if (!array_key_exists($member, $payload)) {
                throw new PayloadStructureException('statement payload is missing required member ' . $member . ' (§7.2)');
            }
        }

        $l2Version = $payload['l2Version'];
        if (!is_string($l2Version) || $l2Version !== '0.1') {
            throw new PayloadStructureException('l2Version must be the exact string "0.1" (§7.2, §15.2 phase 1)');
        }
        $statementType = $payload['statementType'];
        if (!is_string($statementType) || $statementType !== 'resolution') {
            throw new PayloadStructureException('statementType must be "resolution" for L2 0.1 statements (§7.2)');
        }

        $anchorId = $payload['anchorId'];
        $entityId = $payload['entityId'];
        $descriptionLocation = $payload['descriptionLocation'];
        $lifecycleStatus = $payload['lifecycleStatus'];
        $keyId = $payload['keyId'];
        $algorithm = $payload['algorithm'];
        $epochId = $payload['epochId'];
        foreach ([
            'anchorId' => $anchorId,
            'entityId' => $entityId,
            'descriptionLocation' => $descriptionLocation,
            'keyId' => $keyId,
            'algorithm' => $algorithm,
            'epochId' => $epochId,
        ] as $name => $raw) {
            if (!is_string($raw) || $raw === '') {
                throw new PayloadStructureException('statement member ' . $name . ' must be a non-empty exact string (§7.2)');
            }
        }

        // §7.2 lines 298-299: lifecycleStatus MUST be one of the enumerated lowercase values.
        if (!in_array($lifecycleStatus, self::LIFECYCLE_STATUSES, true)) {
            throw new PayloadStructureException('statement member lifecycleStatus must be one of "active", "suspended", "retired" (§7.2, §15.2 phase 1)');
        }

        $issuedAtRaw = $payload['issuedAt'];
        $expiresAtRaw = $payload['expiresAt'];
        $generationRaw = $payload['generation'];
        if (!is_int($issuedAtRaw) || !is_int($expiresAtRaw) || !is_int($generationRaw)) {
            throw new PayloadStructureException('issuedAt, expiresAt, and generation must be JSON integers (§7.2, §15.2 phase 1)');
        }
        $issuedAt = $issuedAtRaw;
        $expiresAt = $expiresAtRaw;
        $generation = $generationRaw;
        if ($generation < 0) {
            throw new PayloadStructureException('generation must be non-negative (§7.2)');
        }
        if ($expiresAt <= $issuedAt) {
            throw new PayloadStructureException('expiresAt must be strictly greater than issuedAt (§7.2, §11.1)');
        }

        return new self(
            $payload,
            $statement->canonicalPayloadOctets(),
            $statement->signatureBase64Url(),
            $statement->signatureOctets(),
            $anchorId,
            $entityId,
            $descriptionLocation,
            $lifecycleStatus,
            $keyId,
            $algorithm,
            $epochId,
            $issuedAt,
            $expiresAt,
            $generation,
        );
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

    public function descriptionLocation(): string
    {
        return $this->descriptionLocation;
    }

    public function lifecycleStatus(): string
    {
        return $this->lifecycleStatus;
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function algorithm(): string
    {
        return $this->algorithm;
    }

    public function epochId(): string
    {
        return $this->epochId;
    }

    public function issuedAt(): int
    {
        return $this->issuedAt;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    public function generation(): int
    {
        return $this->generation;
    }
}
