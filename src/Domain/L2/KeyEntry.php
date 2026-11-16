<?php
// src/Domain/L2/KeyEntry.php
// Authority Record keys 配列の key entry（spec resolver-l2-0.1-draft §9.5, §9.4, §10.1-10.6）。
// keyId は wire publicKey から §9.4 で再計算照合する（fail-closed）。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class KeyEntry
{
    /** @var list<string> */
    private const IDENTITY_FIELDS = ['keyId', 'publicKey', 'algorithm', 'validFrom'];

    private function __construct(
        private readonly string $keyId,
        private readonly string $publicKeyBase64Url,
        private readonly string $publicKeyOctets,
        private readonly string $algorithm,
        private readonly int $validFrom,
        private readonly int $validUntil,
        private readonly string $status,
        private readonly ?int $revokedFrom,
    ) {
    }

    /**
     * §9.5 の key entry 形式を strict に検証し、§9.4 の keyId 再計算照合を通す。
     *
     * @param array<string, mixed> $entry
     */
    public static function fromPayload(array $entry): self
    {
        $members = array_keys($entry);
        sort($members);
        $expected = array_merge(self::IDENTITY_FIELDS, ['status', 'validUntil']);
        sort($expected);

        $status = $entry['status'] ?? null;
        $hasRevokedFrom = array_key_exists('revokedFrom', $entry);
        if (is_string($status)) {
            if (KeyStatus::isRevocationStatus($status)) {
                $expected[] = 'revokedFrom';
                sort($expected);
            }
        }
        if ($members !== $expected) {
            throw new PayloadStructureException('key entry member set is not the §9.5 set: ' . implode(',', $members));
        }

        $keyId = $entry['keyId'] ?? null;
        $publicKey = $entry['publicKey'] ?? null;
        $algorithm = $entry['algorithm'] ?? null;
        $validFrom = $entry['validFrom'] ?? null;
        $validUntil = $entry['validUntil'] ?? null;

        if (!is_string($keyId) || $keyId === '') {
            throw new PayloadStructureException('key entry keyId must be a non-empty string');
        }
        if (!is_string($publicKey) || !Base64Url::isValid($publicKey)) {
            throw new PayloadStructureException('key entry publicKey must be base64url (RFC 4648 §5, no padding)');
        }
        if (!is_string($algorithm) || $algorithm === '') {
            throw new PayloadStructureException('key entry algorithm must be a non-empty string');
        }
        if (!is_int($validFrom) || $validFrom < 0) {
            throw new PayloadStructureException('key entry validFrom must be a non-negative integer');
        }
        if (!is_int($validUntil) || $validUntil < 0) {
            throw new PayloadStructureException('key entry validUntil must be a non-negative integer');
        }
        if (!is_string($status) || !KeyStatus::isKnown($status)) {
            throw new PayloadStructureException('key entry status is not a §9.5 status value');
        }

        $revokedFrom = null;
        if (KeyStatus::isRevocationStatus($status)) {
            if (!is_int($revokedFromRaw = $entry['revokedFrom'] ?? null) || $revokedFromRaw < 0) {
                throw new PayloadStructureException('revokedFrom is required for status ' . $status);
            }
            $revokedFrom = $revokedFromRaw;
        }

        $octets = Base64Url::decode($publicKey);
        if ($octets === '') {
            throw new PayloadStructureException('key entry publicKey must be full-length key material');
        }
        // §9.4 fail-closed: wire keyId must equal the recomputed keyId of the wire publicKey.
        if (!KeyId::matches($keyId, $octets)) {
            throw new PayloadStructureException('keyId does not match the recomputed keyId of publicKey (§9.4)');
        }

        return new self($keyId, $publicKey, $octets, $algorithm, $validFrom, $validUntil, $status, $revokedFrom);
    }

    /**
     * §9.5 entry identity fields (keyId, publicKey, algorithm, validFrom) unchanged.
     */
    public function identityMatches(self $other): bool
    {
        return $this->keyId === $other->keyId
            && $this->publicKeyBase64Url === $other->publicKeyBase64Url
            && $this->algorithm === $other->algorithm
            && $this->validFrom === $other->validFrom;
    }

    /** §10.4/§10.5 validity window containment (Unix seconds, inclusive). */
    public function containsTime(int $time): bool
    {
        return $this->validFrom <= $time && $time <= $this->validUntil;
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function publicKeyBase64Url(): string
    {
        return $this->publicKeyBase64Url;
    }

    public function publicKeyOctets(): string
    {
        return $this->publicKeyOctets;
    }

    public function algorithm(): string
    {
        return $this->algorithm;
    }

    public function validFrom(): int
    {
        return $this->validFrom;
    }

    public function validUntil(): int
    {
        return $this->validUntil;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function revokedFrom(): ?int
    {
        return $this->revokedFrom;
    }
}
