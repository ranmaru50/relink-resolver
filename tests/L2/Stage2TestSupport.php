<?php
// tests/L2/Stage2TestSupport.php
// Stage 2 テスト用の共有ヘルパ：pinned fixtures（L2Fixtures）の wire 構築、seed 派生 keypair
// による再署名（付録 A.1 の seed 定義）、payload / key entry / trail の変形、Trust Anchor
// Binding 構築、chain walk による受理状態の構築。

declare(strict_types=1);

namespace Relink\Resolver\Tests\L2;

use Relink\Resolver\Adapters\SodiumEd25519Verifier;
use L2Fixtures;
use Relink\Resolver\Domain\L2\Base64Url;
use Relink\Resolver\Domain\L2\JcsCanonicalizer;
use Relink\Resolver\Domain\L2\KeyId;
use Relink\Resolver\Domain\L2\RecordChainWalker;
use Relink\Resolver\Domain\L2\StatementVerifier;
use Relink\Resolver\Domain\L2\TrustAnchorBinding;
use Relink\Resolver\Domain\L2\VerifierState;

require_once __DIR__ . '/L2Fixtures.php';

final class Stage2TestSupport
{
    public const ANCHOR_ID = '550e8400-e29b-41d4-a716-446655440000';
    public const ENTITY_ID = 'urn:relink:entity:thermal-sensor-42';
    public const DESCRIPTION_LOCATION = 'https://sensors.example/thermal/42/ar-xml';

    /** §11.2 pinned trusted clock T for the fixture set. */
    public const T = 1769998400;

    /** §11.4 pinned snapshot freshness bound W for the fixture set. */
    public const W = 600;

    /**
     * Appendix A.1 seed rule: seed N = SHA-256(raw bytes of
     * "relink-l2-0.1-draft-test-vector-seed-N"), N = 1..5 pinned order key1..key5.
     *
     * @return array{0: string, 1: string} [private, public]
     */
    public static function keypair(int $n): array
    {
        $seed = hash('sha256', 'relink-l2-0.1-draft-test-vector-seed-' . $n, true);
        $keypair = sodium_crypto_sign_seed_keypair($seed);

        return [sodium_crypto_sign_secretkey($keypair), sodium_crypto_sign_publickey($keypair)];
    }

    /** wire publicKey of seed-derived key N (key1..key5 pinned order). */
    public static function publicKeyOf(int $n): string
    {
        [$private, $public] = self::keypair($n);

        return Base64Url::encode($public);
    }

    /** signature over the exact UTF-8 octets of a canonical payload, signed by key N. */
    public static function sign(string $canonicalOctets, int $n): string
    {
        [$private, $public] = self::keypair($n);

        return Base64Url::encode(sodium_crypto_sign_detached($canonicalOctets, $private));
    }

    /**
     * @return array{canonical: string, signature: string, signerPublicKey: string}
     */
    public static function vector(string $id): array
    {
        foreach (L2Fixtures::signedVectors() as $vector) {
            if ($vector['id'] === $id) {
                return $vector;
            }
        }

        throw new RuntimeException('unknown fixture id: ' . $id);
    }

    /** pinned fixture wire envelope {"payload":canonical,"signature":"sig"}. */
    public static function wire(string $id): string
    {
        $vector = self::vector($id);

        return L2Fixtures::envelope($vector['canonical'], $vector['signature']);
    }

    /** canonical payload JSON of a pinned fixture as an associative array. */
    public static function payloadOf(string $id): array
    {
        $decoded = json_decode(self::vector($id)['canonical'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('fixture payload does not decode: ' . $id);
        }

        return $decoded;
    }

    /**
     * mutated payload wire: mutate top-level payload members, re-canonicalize under JCS,
     * re-sign with seed-derived key N. A null value removes the member.
     *
     * @param array<string, mixed> $changes
     */
    public static function wireWithChanges(string $id, array $changes, int $signerN): string
    {
        $payload = self::payloadOf($id);
        foreach ($changes as $member => $value) {
            if ($value === null) {
                unset($payload[$member]);
            } else {
                $payload[$member] = $value;
            }
        }

        return self::canonicalizeAndSign($payload, $signerN);
    }

    /**
     * mutated key entry wire: mutate one key entry of a Record payload (located by keyId),
     * re-canonicalize, re-sign with seed-derived key N.
     *
     * @param array<string, mixed> $changes
     */
    public static function wireWithKeyChanges(string $id, string $keyId, array $changes, int $signerN): string
    {
        $payload = self::payloadOf($id);
        $found = false;
        foreach ($payload['keys'] as $index => $entry) {
            if ($entry['keyId'] === $keyId) {
                foreach ($changes as $member => $value) {
                    if ($value === null) {
                        unset($entry[$member]);
                    } else {
                        $entry[$member] = $value;
                    }
                }
                $payload['keys'][$index] = $entry;
                $found = true;
            }
        }
        if (!$found) {
            throw new RuntimeException('key entry not found in fixture ' . $id . ': ' . $keyId);
        }

        return self::canonicalizeAndSign($payload, $signerN);
    }

    /**
     * mutated embedded transfer trail wire: mutate the trail payload, re-sign the trail under
     * seed-derived key $trailSignerN, then re-sign the whole Record under key $recordSignerN.
     *
     * @param array<string, mixed> $trailChanges
     */
    public static function wireWithTrailChanges(string $id, array $trailChanges, int $trailSignerN, int $recordSignerN): string
    {
        $payload = self::payloadOf($id);
        $trailPayload = $payload['transfer']['payload'];
        foreach ($trailChanges as $member => $value) {
            if ($value === null) {
                unset($trailPayload[$member]);
            } else {
                $trailPayload[$member] = $value;
            }
        }
        $payload['transfer']['payload'] = $trailPayload;
        $payload['transfer']['signature'] = self::sign(JcsCanonicalizer::canonicalize($trailPayload), $trailSignerN);

        return self::canonicalizeAndSign($payload, $recordSignerN);
    }

    /**
     * raw payload wire text: sign the given raw payload string verbatim (the signing target
     * is the exact octets given; used to produce non-canonical wire text for phase 1 tests).
     */
    public static function wireFromRawPayload(string $rawPayload, int $signerN): string
    {
        return L2Fixtures::envelope($rawPayload, self::sign($rawPayload, $signerN));
    }

    /** swap the first two members of a canonical object string to produce key-unsorted wire text. */
    public static function swapLeadingKeys(string $canonical): string
    {
        $open = $canonical[0];
        if ($open !== '{') {
            throw new RuntimeException('canonical string must start with {');
        }
        $firstKey = strpos($canonical, ':', 1);
        $firstValueEnd = strpos($canonical, ',', $firstKey);
        $secondKey = strpos($canonical, ':', $firstValueEnd + 1);
        $secondValueEnd = strpos($canonical, ',', $secondKey);
        if ($firstKey === false || $firstValueEnd === false || $secondKey === false || $secondValueEnd === false) {
            throw new RuntimeException('canonical string has fewer than two members');
        }
        $firstMember = substr($canonical, 1, $firstValueEnd - 1);
        $secondMember = substr($canonical, $firstValueEnd + 1, $secondKey - $firstValueEnd);

        return '{' . $secondMember . ',' . $firstMember . substr($canonical, $secondValueEnd);
    }

    /** Trust Anchor Binding delivered through the policy channel (§9.3): key1, epoch-0, floor 0. */
    public static function binding(): TrustAnchorBinding
    {
        $key1 = L2Fixtures::keys()['key1'];

        return TrustAnchorBinding::fromPolicyChannel(
            self::ANCHOR_ID,
            self::ENTITY_ID,
            $key1['keyId'],
            $key1['publicKey'],
            'ed25519',
            'epoch-0',
            0,
        );
    }

    /** Trust Anchor Binding on seed-derived key N (used for bootstrap negative cases). */
    public static function bindingWithKey(int $n): TrustAnchorBinding
    {
        $publicKey = self::publicKeyOf($n);
        $keyIdWire = KeyId::compute(Base64Url::decode($publicKey));

        return TrustAnchorBinding::fromPolicyChannel(
            self::ANCHOR_ID,
            self::ENTITY_ID,
            $keyIdWire,
            $publicKey,
            'ed25519',
            'epoch-0',
            0,
        );
    }

    public static function verifier(int $W = self::W): StatementVerifier
    {
        return new StatementVerifier(new SodiumEd25519Verifier(), $W);
    }

    public static function walker(): RecordChainWalker
    {
        return new RecordChainWalker(new SodiumEd25519Verifier());
    }

    /**
     * chain walk a fixture sequence from the Trust Anchor Binding; every fixture must be
     * accepted, otherwise the harness fails fast with the rejection reason.
     *
     * @param list<string> $ids
     */
    public static function walkState(array $ids, ?TrustAnchorBinding $binding = null): VerifierState
    {
        $walker = self::walker();
        $state = $walker->initialState($binding ?? self::binding());
        foreach ($ids as $id) {
            $acceptance = $walker->accept($state, self::wire($id));
            if (!$acceptance->accepted()) {
                throw new RuntimeException('chain walk rejected ' . $id . ' at phase ' . $acceptance->phase() . ': ' . $acceptance->reason());
            }
            $state = $acceptance->state();
        }

        return $state;
    }

    /** wire signed by seed-derived key N over the fixture's canonical payload bytes verbatim. */
    public static function wireSignedBy(string $id, int $signerN): string
    {
        $vector = self::vector($id);

        return L2Fixtures::envelope($vector['canonical'], self::sign($vector['canonical'], $signerN));
    }

    /**
     * wire carrying the given envelope signature verbatim; payload changes are re-canonicalized.
     * Used to deliver foreign-length signatures that must reach later phases, not phase 1.
     *
     * @param array<string, mixed> $payloadChanges
     */
    public static function wireWithEnvelopeSignature(string $id, string $signatureBase64Url, array $payloadChanges): string
    {
        $payload = self::payloadOf($id);
        foreach ($payloadChanges as $member => $value) {
            if ($value === null) {
                unset($payload[$member]);
            } else {
                $payload[$member] = $value;
            }
        }

        return L2Fixtures::envelope(JcsCanonicalizer::canonicalize($payload), $signatureBase64Url);
    }

    /** Trust Anchor Binding with an explicit algorithm label (bootstrap matching negative cases). */
    public static function bindingWithAlgorithm(string $algorithm): TrustAnchorBinding
    {
        $publicKey = self::publicKeyOf(1);

        return TrustAnchorBinding::fromPolicyChannel(
            self::ANCHOR_ID,
            self::ENTITY_ID,
            KeyId::compute(Base64Url::decode($publicKey)),
            $publicKey,
            $algorithm,
            'epoch-0',
            0,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function canonicalizeAndSign(array $payload, int $signerN): string
    {
        $canonical = JcsCanonicalizer::canonicalize($payload);

        return L2Fixtures::envelope($canonical, self::sign($canonical, $signerN));
    }
}
