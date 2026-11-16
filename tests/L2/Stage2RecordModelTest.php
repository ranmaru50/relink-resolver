<?php
// tests/L2/Stage2RecordModelTest.php
// Record model（§9.5 payload 形式、key entry、prevRecordDigest、embedded transfer trail）と
// §9.4 keyId 再計算の fail-closed 検証。対応 Conformance cases:
// L2-VER-009（Record 0 pinned vector）、L2-AUT-002（prevRecordDigest は payload を binds）、
// L2-AUT-011（trail forbidden members）、L2-AUT-021（key entry 形式）、L2-AUT-022（keyId 再計算）。

declare(strict_types=1);

namespace Relink\Resolver\Tests\L2;

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Domain\L2\KeyEntry;
use Relink\Resolver\Domain\L2\KeyStatus;
use Relink\Resolver\Domain\L2\PayloadStructureException;
use Relink\Resolver\Domain\L2\Record;
use Relink\Resolver\Domain\L2\WireStatementException;
use L2Fixtures;

require_once __DIR__ . '/Stage2TestSupport.php';

final class Stage2RecordModelTest extends TestCase
{
    /** L2-VER-009 / Appendix A.5 (Record 0): pinned Record 0 parses and exposes pinned fields. */
    public function testRecord0PinnedVectorParsesWithPinnedAccessors(): void
    {
        $record = Record::fromWire(Stage2TestSupport::wire('Record0'));

        $this->assertSame('authority', $record->payload()['statementType']);
        $this->assertSame(0, $record->recordGeneration());
        $this->assertSame('epoch-0', $record->epochId());
        $this->assertSame(0, $record->statementFloor());
        $this->assertSame(1769996400, $record->issuedAt());
        $this->assertSame(1770000000, $record->expiresAt());
        $this->assertSame('ECMERdvpV0k', $record->signerKeyId());
        $this->assertNull($record->prevRecordDigest());
        $this->assertFalse($record->hasTransfer());
        $this->assertCount(1, $record->keys());
        $entry = $record->key('ECMERdvpV0k');
        $this->assertInstanceOf(KeyEntry::class, $entry);
        $this->assertSame(KeyStatus::CURRENT, $entry->status());
        $this->assertNull($entry->revokedFrom());
        $this->assertTrue($entry->containsTime(1769996400));
        $this->assertFalse($entry->containsTime(1770000001));
    }

    /**
     * L2-AUT-002: prevRecordDigest binds payloads, not signatures. The pinned digests of
     * Records 0..2 are the prevRecordDigest values carried by Records 1..3 (Appendix A.5),
     * and the wire envelope is NOT part of the digest.
     */
    public function testPayloadDigestIsSha256OfCanonicalPayloadOnly(): void
    {
        $record0 = Record::fromWire(Stage2TestSupport::wire('Record0'));
        $record1 = Record::fromWire(Stage2TestSupport::wire('Record1'));
        $record2 = Record::fromWire(Stage2TestSupport::wire('Record2'));
        $record3 = Record::fromWire(Stage2TestSupport::wire('Record3'));

        $this->assertSame('36ce3c7bf819434ebc79e534649873081ee0999af85c798c6586235680809afb', $record0->payloadDigest());
        $this->assertSame('870b931dd8f9ad01a6a037524842549ad60fcde1066dbc9caf4f3e6140102f61', $record1->payloadDigest());
        $this->assertSame('39808d467859fcad7589d70d54e0666038ac039d4956964ddda751f10f3e3234', $record2->payloadDigest());

        $this->assertSame($record0->payloadDigest(), $record1->prevRecordDigest());
        $this->assertSame($record1->payloadDigest(), $record2->prevRecordDigest());
        $this->assertSame($record2->payloadDigest(), $record3->prevRecordDigest());

        // the digest covers only the payload member: the envelope/signature is never bound
        $this->assertNotSame(hash('sha256', Stage2TestSupport::wire('Record0')), $record0->payloadDigest());
        $this->assertNotSame(hash('sha256', $record0->signatureOctets()), $record0->payloadDigest());
    }

    /** L2-AUT-002 negative: a Record whose digest binds the envelope is rejected at chain walk. */
    public function testRecordDigestingTheEnvelopeIsRejectedAtChainWalk(): void
    {
        $envelopeDigest = hash('sha256', Stage2TestSupport::wire('Record0'));
        $state = Stage2TestSupport::walkState(['Record0']);
        $wire = Stage2TestSupport::wireWithChanges('Record1', ['prevRecordDigest' => $envelopeDigest], 1);
        $acceptance = Stage2TestSupport::walker()->accept($state, $wire);

        $this->assertFalse($acceptance->accepted());
        $this->assertTrue($acceptance->state()->hasChainStop());
        $this->assertSame(5, $acceptance->phase());
        $this->assertSame(0, $acceptance->state()->acceptedMaximum());
    }

    /** §9.5: Record payload member set is exact; an extra member is rejected. */
    public function testRecordPayloadMemberSetIsExact(): void
    {
        $wire = Stage2TestSupport::wireWithChanges('Record0', ['extension' => 'x'], 1);

        $this->expectException(PayloadStructureException::class);
        Record::fromWire($wire);
    }

    /** §9.5: a required member missing from the Record payload is rejected. */
    public function testRecordPayloadMissingMemberIsRejected(): void
    {
        $wire = Stage2TestSupport::wireWithChanges('Record0', ['signerKeyId' => null], 1);

        $this->expectException(PayloadStructureException::class);
        Record::fromWire($wire);
    }

    /** §9.5: statementType must be "authority" for Records. */
    public function testRecordStatementTypeMustBeAuthority(): void
    {
        $wire = Stage2TestSupport::wireWithChanges('Record0', ['statementType' => 'resolution'], 1);

        $this->expectException(PayloadStructureException::class);
        Record::fromWire($wire);
    }

    /** §9.5: expiresAt must be strictly greater than issuedAt on a Record. */
    public function testRecordExpiresAtMustExceedIssuedAt(): void
    {
        $wire = Stage2TestSupport::wireWithChanges('Record0', ['expiresAt' => 1769996400], 1);

        $this->expectException(PayloadStructureException::class);
        Record::fromWire($wire);
    }

    /** §9.5: prevRecordDigest must be null for Record 0 and lowercase hex SHA-256 for G > 0. */
    public function testPrevRecordDigestFormRules(): void
    {
        $wire = Stage2TestSupport::wireWithChanges('Record0', ['prevRecordDigest' => str_repeat('0', 64)], 1);
        $this->expectException(PayloadStructureException::class);
        Record::fromWire($wire);
    }

    public function testPrevRecordDigestMustBeLowercaseHexForRecord1(): void
    {
        $wire = Stage2TestSupport::wireWithChanges('Record1', ['prevRecordDigest' => 'ZZ'], 1);

        $this->expectException(PayloadStructureException::class);
        Record::fromWire($wire);
    }

    /** §9.5: the keys array must be non-empty. */
    public function testRecordKeysArrayMustBeNonEmpty(): void
    {
        $wire = Stage2TestSupport::wireWithChanges('Record0', ['keys' => []], 1);

        $this->expectException(PayloadStructureException::class);
        Record::fromWire($wire);
    }

    /** §9.5: duplicate keyId entries in the keys array are rejected. */
    public function testRecordKeysArrayDuplicateKeyIdIsRejected(): void
    {
        $payload = Stage2TestSupport::payloadOf('Record1');
        $payload['keys'][] = $payload['keys'][0];
        $canonical = \Relink\Resolver\Domain\L2\JcsCanonicalizer::canonicalize($payload);
        $wire = L2Fixtures::envelope($canonical, Stage2TestSupport::sign($canonical, 1));

        $this->expectException(PayloadStructureException::class);
        Record::fromWire($wire);
    }

    /**
     * L2-AUT-022, L2-VER-002 (keyid-recompute-mismatch subcase): §9.4 fail-closed — a key
     * entry whose wire keyId does not equal the recomputed keyId of its wire publicKey is
     * rejected (INVALID for the Record; the dependent statement path is covered in the
     * chain walk and verifier tests).
     */
    public function testKeyEntryKeyIdRecomputationIsFailClosed(): void
    {
        $key4PublicKey = L2Fixtures::keys()['key4']['publicKey'];
        $wire = Stage2TestSupport::wireWithKeyChanges('Record2', 'G8VbXumewVs', ['publicKey' => $key4PublicKey], 4);

        try {
            Record::fromWire($wire);
            $this->fail('§9.4 keyId recomputation mismatch must be rejected fail-closed');
        } catch (PayloadStructureException $e) {
            $this->assertStringContainsString('§9.4', $e->getMessage());
            $this->assertSame('INVALID', $e->verdict()->value);
        }
    }

    /** §9.5/§13.4: revokedFrom is required exactly on revoked and compromised entries. */
    public function testRevokedEntryRequiresRevokedFrom(): void
    {
        $wire = Stage2TestSupport::wireWithKeyChanges('Record4R', 'G8VbXumewVs', ['revokedFrom' => null], 3);

        $this->expectException(PayloadStructureException::class);
        Record::fromWire($wire);
    }

    public function testCurrentEntryMustNotCarryRevokedFrom(): void
    {
        $wire = Stage2TestSupport::wireWithKeyChanges('Record1', '4KKXd7XSJW8', ['revokedFrom' => 1], 1);

        $this->expectException(PayloadStructureException::class);
        Record::fromWire($wire);
    }

    /** §9.5: key entry member set is exact (extra member rejected). */
    public function testKeyEntryMemberSetIsExact(): void
    {
        $wire = Stage2TestSupport::wireWithKeyChanges('Record0', 'ECMERdvpV0k', ['extension' => 'x'], 1);

        $this->expectException(PayloadStructureException::class);
        Record::fromWire($wire);
    }

    /** §9.5: key entry status must be a §9.5 status value. */
    public function testKeyEntryStatusMustBeKnown(): void
    {
        $wire = Stage2TestSupport::wireWithKeyChanges('Record0', 'ECMERdvpV0k', ['status' => 'active'], 1);

        $this->expectException(PayloadStructureException::class);
        Record::fromWire($wire);
    }

    /** §9.5: key entry publicKey must be base64url key material. */
    public function testKeyEntryPublicKeyMustBeBase64url(): void
    {
        $wire = Stage2TestSupport::wireWithKeyChanges('Record0', 'ECMERdvpV0k', ['publicKey' => 'not-base64url!'], 1);

        $this->expectException(PayloadStructureException::class);
        Record::fromWire($wire);
    }

    /**
     * L2-AUT-011: the trail payload must not carry keyId/generation/issuedAt/expiresAt/
     * descriptionLocation/lifecycleStatus/effectiveGeneration.
     */
    public function testTrailPayloadForbidsKeyStatementFields(): void
    {
        $wire = Stage2TestSupport::wireWithTrailChanges('Record2', ['keyId' => 'ECMERdvpV0k'], 2, 2);

        $this->expectException(PayloadStructureException::class);
        Record::fromWire($wire);
    }

    /** §12.2: the transfer member must carry exactly { payload, signature }. */
    public function testTransferMemberCarriesExactlyPayloadAndSignature(): void
    {
        $wire = Stage2TestSupport::wireWithChanges('Record2', ['transfer' => ['payload' => [], 'signature' => 'x', 'extra' => 'y']], 2);

        $this->expectException(PayloadStructureException::class);
        Record::fromWire($wire);
    }

    /**
     * §12.2 wire canonicality of the embedded trail: the trail payload bytes must appear
     * JCS-canonical on the wire; a key-reordered trail payload is rejected fail-closed.
     */
    public function testEmbeddedTrailPayloadMustBeCanonicalOnTheWire(): void
    {
        $canonical = Stage2TestSupport::vector('Record2')['canonical'];
        $trailCanonical = Stage2TestSupport::vector('Trail2')['canonical'];
        $swappedTrail = Stage2TestSupport::swapLeadingKeys($trailCanonical);
        $rawPayload = str_replace($trailCanonical, $swappedTrail, $canonical);
        $wire = Stage2TestSupport::wireFromRawPayload($rawPayload, 2);

        // wire payload bytes が非正準（nested trail の member 順）→ phase 1 wire canonicality で
        // WireStatementException（INVALID）。§12.2 の trail canonicality はこの上位検査に包含される。
        $this->expectException(WireStatementException::class);
        Record::fromWire($wire);
    }

    /** §12.2: Record 0 must not carry a transfer member. */
    public function testTransferMemberOnRecord0IsRejectedAtChainWalk(): void
    {
        $trailPayload = Stage2TestSupport::payloadOf('Trail2');
        $wire = Stage2TestSupport::wireWithChanges('Record0', [
            'transfer' => ['payload' => $trailPayload, 'signature' => Stage2TestSupport::vector('Trail2')['signature']],
        ], 1);

        $this->expectException(PayloadStructureException::class);
        Record::fromWire($wire);
    }

    /** L2-VER-014/record-wire-duplicate: duplicate member names in raw wire text are rejected before parsing (Stage 1 wire canonicality), so Record parsing fails as INVALID. */
    public function testRecordWireDuplicateMembersAreRejectedAtPhase1Canonicality(): void
    {
        $canonical = Stage2TestSupport::vector('Record2')['canonical'];
        $rawPayload = substr($canonical, 0, -1) . ',"signerKeyId":"4KKXd7XSJW8"}';
        $wire = Stage2TestSupport::wireFromRawPayload($rawPayload, 2);

        $this->expectException(WireStatementException::class);
        Record::fromWire($wire);
    }

    /** L2-VER-014/trail-verification-failure (missing trail): Record 2 without the transfer member parses as a structurally valid Record; chain acceptance stops at Record 1 in the chain walk test. */
    public function testRecordWithoutTransferParsesAndIsJudgedAtChainAcceptance(): void
    {
        $wire = Stage2TestSupport::wireWithChanges('Record2', ['transfer' => null], 2);
        $record = Record::fromWire($wire);

        $this->assertFalse($record->hasTransfer());
        $this->assertSame(2, $record->recordGeneration());
    }

    /** pinned Record 2 accessors: signer key2, embedded trail fields per Appendix A.5. */
    public function testRecord2ExposesEmbeddedTrailAccessors(): void
    {
        $record = Record::fromWire(Stage2TestSupport::wire('Record2'));
        $trail = $record->transfer();

        $this->assertNotNull($trail);
        $this->assertSame('4KKXd7XSJW8', $trail->outgoingKeyId());
        $this->assertSame('G8VbXumewVs', $trail->incomingKeyId());
        $this->assertSame('epoch-0', $trail->epochId());
        $this->assertSame('epoch-1', $trail->newEpochId());
        $this->assertSame(2, $trail->recordGeneration());
        $this->assertSame(0, $trail->statementFloor());
        $this->assertSame('ed25519', $trail->algorithm());
        $this->assertSame(Stage2TestSupport::vector('Trail2')['canonical'], $trail->canonicalPayloadOctets());
    }

    /** §9.5 current key view used by chain acceptance and snapshot coverage. */
    public function testCurrentKeysOfRecord4AIncludeKey3AndKey4(): void
    {
        $record = Record::fromWire(Stage2TestSupport::wire('Record4A'));
        $current = $record->currentKeys();
        $ids = array_map(static fn (KeyEntry $entry): string => $entry->keyId(), $current);
        sort($ids);

        $this->assertSame(['ECYh7yJFAn8', 'G8VbXumewVs'], $ids);
        $key3 = $record->key('G8VbXumewVs');
        $this->assertNotNull($key3);
        $this->assertSame(KeyStatus::CURRENT, $key3->status());
    }
}
