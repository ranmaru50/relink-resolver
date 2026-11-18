<?php
// tests/L2/Stage3RepositoryTest.php
// Stage 3（Issue #28）: Trust Anchor Binding、Authority Records、L2 Statement の永続化。
// Covers L2-SRV-002 (verbatim, strictly ascending 0..G Records, detectable chain gaps),
// §9.3/§9.4 (Trust Anchor Binding fields, stored key_id checked against recomputation from
// publicKey on load, fail-closed),
// §9.5 (derived Record columns stored beside the verbatim wire),
// L2-PRO-001 / L2-PRO-004 / L2-PRO-007 (§7.3 wire stored byte-exactly, non-BMP payload included).

declare(strict_types=1);

namespace Relink\Resolver\Tests\L2;

use PDO;
use PHPUnit\Framework\TestCase;
use Relink\Resolver\Adapters\SqliteL2Repository;
use Relink\Resolver\Adapters\SqliteMigrator;
use Relink\Resolver\Domain\L2\Base64Url;
use Relink\Resolver\Domain\L2\KeyId;
use Relink\Resolver\Domain\L2\PayloadStructureException;
use Relink\Resolver\Domain\L2\Record;
use Relink\Resolver\Domain\L2\StatementPayload;

require_once __DIR__ . '/L2Fixtures.php';
require_once __DIR__ . '/Stage2TestSupport.php';

final class Stage3RepositoryTest extends TestCase
{
    /** A distinct anchor used to prove per-anchor scoping. */
    private const ANCHOR_B = '550e8400-e29b-41d4-a716-446655440999';

    private string $path;

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'relink-l2-');
        SqliteMigrator::migrate($this->path);
    }

    protected function tearDown(): void
    {
        foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
            $file = $this->path . $suffix;
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function repository(): SqliteL2Repository
    {
        return new SqliteL2Repository($this->path);
    }

    private function pdo(): PDO
    {
        return new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * Raw DB read for verifying stored rows. The cursor and connection are closed here so
     * no SQLite shared lock survives to block the next append (test hygiene around §9.5
     * direct-column inspection); the repository adapter owns all mutating writes.
     *
     * @param array<string, int|string> $params
     * @return array<string, mixed>
     */
    private function rawFetch(string $sql, array $params): array
    {
        $pdo = $this->pdo();
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        $statement->closeCursor();
        $pdo = null;

        return is_array($row) ? $row : [];
    }

    private function appendRecord(string $id): string
    {
        $wire = Stage2TestSupport::wire($id);
        $this->repository()->appendRecord(Record::fromWire($wire), $wire);

        return $wire;
    }

    /** §9.3: every Trust Anchor Binding field survives the store/read round-trip. */
    public function testTrustAnchorBindingRoundTripsAllPolicyFields(): void
    {
        $binding = Stage2TestSupport::binding();
        $repository = $this->repository();
        $repository->storeBinding($binding);

        $stored = $repository->findBinding(Stage2TestSupport::ANCHOR_ID);
        self::assertNotNull($stored, 'L2-SRV-002 precondition: Trust Anchor Binding is stored per anchor');
        self::assertSame(Stage2TestSupport::ANCHOR_ID, $stored->anchorId(), '§9.3: anchorId round-trips');
        self::assertSame(Stage2TestSupport::ENTITY_ID, $stored->entityId(), '§9.3: entityId round-trips');
        self::assertSame(\L2Fixtures::keys()['key1']['keyId'], $stored->keyId(), '§9.3/§9.4: keyId per §10.1 derivation round-trips');
        self::assertSame(\L2Fixtures::keys()['key1']['publicKey'], $stored->publicKeyBase64Url(), '§9.3: full-length publicKey preserved byte-exactly');
        self::assertSame('ed25519', $stored->algorithm(), '§9.3: algorithm round-trips');
        self::assertSame('epoch-0', $stored->initialEpochId(), '§9.3: initialEpochId round-trips');
        self::assertSame(0, $stored->initialStatementFloor(), '§9.3: initialStatementFloor round-trips');
        self::assertNull($repository->findBinding(self::ANCHOR_B), '§9.3: bindings are scoped per anchor');
    }

    /**
     * §9.4 fail-closed (P2-1): a stored key_id row that does not match the recomputation
     * from the stored public key must surface as a load failure. The repository passes the
     * stored key_id verbatim to TrustAnchorBinding::fromPolicyChannel, which performs the
     * §9.4 check; silently replacing the stored key_id with the derived value makes the
     * check unreachable.
     */
    public function testBindingLoadRejectsStoredKeyIdThatFailsRecomputation(): void
    {
        $publicKey = \L2Fixtures::keys()['key1']['publicKey'];
        $statement = $this->pdo()->prepare('INSERT INTO trust_anchor_bindings (anchor_id, entity_id, key_id, public_key, algorithm, initial_epoch_id, initial_statement_floor, created_at) VALUES (:anchor_id, :entity_id, :key_id, :public_key, :algorithm, :epoch, :floor, CURRENT_TIMESTAMP)');
        $statement->execute([
            'anchor_id' => Stage2TestSupport::ANCHOR_ID,
            'entity_id' => Stage2TestSupport::ENTITY_ID,
            'key_id' => 'WRONGKEYID',
            'public_key' => $publicKey,
            'algorithm' => 'ed25519',
            'epoch' => 'epoch-0',
            'floor' => 0,
        ]);
        $statement->closeCursor();

        $this->expectException(PayloadStructureException::class);
        $this->expectExceptionMessage('§9.4');
        $this->repository()->findBinding(Stage2TestSupport::ANCHOR_ID);
    }

    /** L2-SRV-002: Records come back verbatim, ascending 0..G, scoped per anchor. */
    public function testRecordWiresRoundTripVerbatimAndAscendingPerAnchor(): void
    {
        $repository = $this->repository();
        $anchor = Stage2TestSupport::ANCHOR_ID;
        // Appended out of order to prove the returned order is storage order, not insertion order.
        foreach (['Record3', 'Record1', 'Record6', 'Record0', 'Record5', 'Record2', 'Record4'] as $id) {
            $wire = Stage2TestSupport::wire($id);
            $repository->appendRecord(Record::fromWire($wire), $wire);
        }

        $expected = array_map(static fn (string $id): string => Stage2TestSupport::wire($id), ['Record0', 'Record1', 'Record2', 'Record3', 'Record4', 'Record5', 'Record6']);
        self::assertSame($expected, $repository->recordWires($anchor), 'L2-SRV-002: Records served as verbatim wire strings in strictly ascending 0..6 order');
        self::assertSame(6, $repository->maxGeneration($anchor), 'L2-SRV-002: max generation is 6');

        // L2-SRV-002/transport-framing: the wire envelope survives byte-exactly (Record1 keeps the pinned envelope).
        self::assertSame(Stage2TestSupport::wire('Record1'), $repository->recordWires($anchor)[1], 'L2-PRO-007: envelope form kept byte-identical on round-trip');

        $wireB = Stage2TestSupport::wireWithChanges('Record0', ['anchorId' => self::ANCHOR_B], 1);
        $repository->appendRecord(Record::fromWire($wireB), $wireB);
        self::assertSame([$wireB], $repository->recordWires(self::ANCHOR_B), 'L2-SRV-002: Records are scoped per anchor');
        self::assertSame($expected, $repository->recordWires($anchor), 'L2-SRV-002: anchor B append must not touch anchor A storage');
    }

    /** §9.5: the derived columns needed for queries are persisted beside the verbatim wire. */
    public function testDerivedRecordColumnsArePersisted(): void
    {
        $wire = Stage2TestSupport::wire('Record3');
        $record = Record::fromWire($wire);
        $this->repository()->appendRecord($record, $wire);

        $stored = $this->rawFetch('SELECT entity_id, signer_key_id, epoch_id, issued_at, expires_at, statement_floor, prev_record_digest, stored_at FROM l2_records WHERE anchor_id = :anchor_id AND record_generation = :generation', ['anchor_id' => Stage2TestSupport::ANCHOR_ID, 'generation' => 3]);
        self::assertNotSame([], $stored, '§9.5: the Record row exists for the anchor and generation');
        self::assertSame($record->entityId(), $stored['entity_id'], '§9.5 derived column entity_id');
        self::assertSame($record->signerKeyId(), $stored['signer_key_id'], '§9.5 derived column signer_key_id');
        self::assertSame($record->epochId(), $stored['epoch_id'], '§9.5 derived column epoch_id');
        self::assertSame($record->issuedAt(), (int) $stored['issued_at'], '§9.5 derived column issued_at');
        self::assertSame($record->expiresAt(), (int) $stored['expires_at'], '§9.5 derived column expires_at');
        self::assertSame($record->statementFloor(), (int) $stored['statement_floor'], '§9.5 derived column statement_floor');
        self::assertSame($record->prevRecordDigest(), $stored['prev_record_digest'], '§9.5 derived column prev_record_digest (lowercase hex for Record G>0, §9.5)');
        self::assertNotSame('', (string) $stored['stored_at'], '§9.5 derived column stored_at is recorded at append time');

        $wire0 = $this->appendRecord('Record0');
        $row0 = $this->rawFetch('SELECT prev_record_digest FROM l2_records WHERE anchor_id = :anchor_id AND record_generation = 0', ['anchor_id' => Stage2TestSupport::ANCHOR_ID]);
        self::assertNotSame([], $row0, '§9.5: Record 0 row exists after append');
        self::assertNull($row0['prev_record_digest'], '§9.5: Record 0 stores prev_record_digest as NULL, §9.5 null rule preserved on round-trip: ' . $wire0);
    }

    /** L2-SRV-002 / §6.3: a missing Record between 0 and maxGeneration is detectable as a gap. */
    public function testGapBetweenZeroAndMaxIsDetectedAndFillable(): void
    {
        $repository = $this->repository();
        foreach (['Record0', 'Record1', 'Record2', 'Record4', 'Record5', 'Record6'] as $id) {
            $wire = Stage2TestSupport::wire($id);
            $repository->appendRecord(Record::fromWire($wire), $wire);
        }

        self::assertNull($repository->contiguousRecordWires(Stage2TestSupport::ANCHOR_ID), 'L2-SRV-002: missing Record 3 between 0 and 6 is a detectable gap (§6.3: verifier reports UNVERIFIED, the gapful set must never be served as complete)');
        self::assertSame(6, $repository->maxGeneration(Stage2TestSupport::ANCHOR_ID), '§6.3: max generation is still observable at the gap');

        $wire3 = $this->appendRecord('Record3');
        $contiguous = $repository->contiguousRecordWires(Stage2TestSupport::ANCHOR_ID);
        self::assertIsArray($contiguous, 'L2-SRV-002: filling the gap yields the complete 0..6 prefix');
        self::assertCount(7, $contiguous, 'L2-SRV-002: complete prefix 0..6 has 7 Records');
        self::assertSame($wire3, $contiguous[3], 'L2-SRV-002: filled Record 3 is verbatim at index 3');
    }

    /** §6.3: a chain missing Record 0 is a bootstrap gap, not a partial prefix. */
    public function testMissingRecordZeroIsADetectedGap(): void
    {
        $repository = $this->repository();
        foreach (['Record1', 'Record2'] as $id) {
            $wire = Stage2TestSupport::wire($id);
            $repository->appendRecord(Record::fromWire($wire), $wire);
        }

        self::assertNull($repository->contiguousRecordWires(Stage2TestSupport::ANCHOR_ID), '§6.3: verifier requires Record 0; a set starting at 1 is a gap');

        $wire0 = $this->appendRecord('Record0');
        $contiguous = $repository->contiguousRecordWires(Stage2TestSupport::ANCHOR_ID);
        self::assertIsArray($contiguous, '§6.3: completing 0..2 yields a complete prefix');
        self::assertSame($wire0, $contiguous[0], 'L2-SRV-002: Record 0 verbatim at position 0');
    }

    /** §6.3: an empty record set is not a complete prefix. */
    public function testEmptyRecordSetIsNotACompletePrefix(): void
    {
        self::assertNull($this->repository()->contiguousRecordWires(Stage2TestSupport::ANCHOR_ID), '§6.3: no Record 0 in storage is a bootstrap gap; must not be served as complete');
        self::assertSame(-1, $this->repository()->maxGeneration(Stage2TestSupport::ANCHOR_ID), '§6.3: max generation is observable as -1 for an empty set');
        self::assertNull($this->repository()->expiresAtOfMaxGeneration(Stage2TestSupport::ANCHOR_ID), '§14.2: no expiry bound is derivable from an empty set');
    }

    /** Fail-closed persistence: a duplicate generation append is rejected and the stored wire stays byte-verbatim. */
    public function testDuplicateGenerationAppendIsRejected(): void
    {
        $repository = $this->repository();
        $wire0 = Stage2TestSupport::wire('Record0');
        $repository->appendRecord(Record::fromWire($wire0), $wire0);

        try {
            $repository->appendRecord(Record::fromWire($wire0), $wire0);
            self::fail('L2-SRV-002: duplicate generation append must fail closed (unique record generation per anchor)');
        } catch (\RuntimeException $exception) {
            self::assertSame('RECORD_EXISTS', $exception->getMessage());
        }
        self::assertSame([$wire0], $repository->recordWires(Stage2TestSupport::ANCHOR_ID), 'L2-PRO-007: rejected append leaves the stored wire byte-verbatim and single');
    }

    /** L2-PRO-004 / L2-PRO-007 / §7.3: statement wires are stored byte-exactly, non-BMP payload included. */
    public function testStatementWireRoundTripIsByteExactIncludingNonBmp(): void
    {
        $wire = Stage2TestSupport::wire('A4-Vector3');
        $statement = StatementPayload::fromWire($wire);
        $repository = $this->repository();
        $repository->appendStatement($statement, $wire);

        self::assertSame([$wire], $repository->statementWires(Stage2TestSupport::ANCHOR_ID), 'L2-PRO-007: statement envelope stored byte-verbatim (non-BMP payload preserved byte-exactly, §7.3/L2-PRO-004)');
        self::assertTrue(str_contains($repository->statementWires(Stage2TestSupport::ANCHOR_ID)[0], hex2bin('f09f9880')), 'L2-PRO-004: the non-BMP U+1F600 key survives as raw UTF-8 bytes');
        self::assertSame(1, $repository->statementCount(Stage2TestSupport::ANCHOR_ID), '§6.3 storage scope: statements per anchor');
        self::assertSame(0, $repository->statementCount(self::ANCHOR_B), '§6.3 storage scope: statements are scoped per anchor');

        $wireB = Stage2TestSupport::wireWithChanges('A4-Vector3', ['anchorId' => self::ANCHOR_B], 1);
        $repository->appendStatement(StatementPayload::fromWire($wireB), $wireB);
        self::assertSame(1, $repository->statementCount(self::ANCHOR_B), '§6.3 storage scope: anchor B statement stored separately');
        self::assertSame([$wire], $repository->statementWires(Stage2TestSupport::ANCHOR_ID), 'L2-SRV-002: anchor B append must not touch anchor A storage');
    }

    /** Statement wires come back ordered ascending by generation, per anchor. */
    public function testStatementWiresAreAscendingByGenerationAndPerAnchor(): void
    {
        $repository = $this->repository();
        $anchor = Stage2TestSupport::ANCHOR_ID;
        // Append out of order: generation 2 first, then two generation-1 statements with distinct key ids.
        foreach (['S9', 'S7', 'S6'] as $id) {
            $wire = Stage2TestSupport::wire($id);
            $repository->appendStatement(StatementPayload::fromWire($wire), $wire);
        }

        $wires = $repository->statementWires($anchor);
        $generations = array_map(static fn (string $wire): int => StatementPayload::fromWire($wire)->generation(), $wires);
        $keys = array_map(static fn (string $wire): string => StatementPayload::fromWire($wire)->keyId(), $wires);
        self::assertSame([1, 1, 2], $generations, 'L2-SRV-002 storage order: statement wires ascending by generation');
        self::assertSame(['ECYh7yJFAn8', 'G8VbXumewVs', 'ECYh7yJFAn8'], $keys, 'L2-SRV-002 storage order: ties within one generation ordered deterministically by key id');
        self::assertSame([Stage2TestSupport::wire('S6'), Stage2TestSupport::wire('S7'), Stage2TestSupport::wire('S9')], $wires, 'L2-SRV-002: statement wires verbatim on round-trip');
    }
}
