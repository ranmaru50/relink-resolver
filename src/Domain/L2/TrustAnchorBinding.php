<?php
// src/Domain/L2/TrustAnchorBinding.php
// Trust Anchor Binding（spec resolver-l2-0.1-draft §9.3, §9.6 item 1, §9.8）。
// Record 0 はこの binding 通过（policy channel）でのみ受理される。expected anchorId は
// out-of-band（§15.1）であり、wire から得られない。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class TrustAnchorBinding
{
    private function __construct(
        private readonly string $anchorId,
        private readonly string $entityId,
        private readonly string $keyId,
        private readonly string $publicKeyBase64Url,
        private readonly string $publicKeyOctets,
        private readonly string $algorithm,
        private readonly string $initialEpochId,
        private readonly int $initialStatementFloor,
    ) {
    }

    public static function fromPolicyChannel(
        string $anchorId,
        string $entityId,
        string $keyId,
        string $publicKeyBase64Url,
        string $algorithm,
        string $initialEpochId,
        int $initialStatementFloor,
    ): self {
        if ($anchorId === '' || $entityId === '') {
            throw new PayloadStructureException('Trust Anchor Binding anchorId and entityId must be non-empty (§9.3)');
        }
        if ($keyId === '' || $algorithm === '' || $initialEpochId === '') {
            throw new PayloadStructureException('Trust Anchor Binding keyId, algorithm and epochId must be non-empty (§9.3)');
        }
        if ($initialStatementFloor < 0) {
            throw new PayloadStructureException('Trust Anchor Binding initial statementFloor must be non-negative (§9.3)');
        }
        if (!Base64Url::isValid($publicKeyBase64Url)) {
            throw new PayloadStructureException('Trust Anchor Binding publicKey must be base64url full-length (§9.3)');
        }

        $octets = Base64Url::decode($publicKeyBase64Url);
        if ($octets === '') {
            throw new PayloadStructureException('Trust Anchor Binding publicKey must be full-length key material (§9.3)');
        }
        // §9.4 fail-closed: the binding's keyId must equal the recomputed keyId.
        if (!KeyId::matches($keyId, $octets)) {
            throw new PayloadStructureException('Trust Anchor Binding keyId does not match the recomputed publicKey (§9.4)');
        }

        return new self($anchorId, $entityId, $keyId, $publicKeyBase64Url, $octets, $algorithm, $initialEpochId, $initialStatementFloor);
    }

    public function anchorId(): string
    {
        return $this->anchorId;
    }

    public function entityId(): string
    {
        return $this->entityId;
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

    public function initialEpochId(): string
    {
        return $this->initialEpochId;
    }

    public function initialStatementFloor(): int
    {
        return $this->initialStatementFloor;
    }
}
