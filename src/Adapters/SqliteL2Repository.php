<?php
// src/Adapters/SqliteL2Repository.php
// L2 権限ストレージのアダプタ（migration 004）。Trust Anchor Binding、Authority Record、
// L2 Statement を同一 SQLite 接続で扱う。wire は byte-for-byte の verbatim 保存であり、
// 読み出しは派生列ではなく wire をそのまま返す（L2-SRV-002、L2-PRO-007）。
// 連続性（0..G）とギャップ検出は §6.3 の必須条件: verifier は 0..G を全て必要とし、
// ギャップは UNVERIFIED を意味する。ギャップのある集合は完全な集合として提供しない。

declare(strict_types=1);

namespace Relink\Resolver\Adapters;

use PDO;
use PDOException;
use RuntimeException;
use Relink\Resolver\Domain\L2\Record;
use Relink\Resolver\Domain\L2\StatementPayload;
use Relink\Resolver\Domain\L2\TrustAnchorBinding;
use Relink\Resolver\Ports\L2RecordRepository;
use Relink\Resolver\Ports\L2StatementRepository;
use Relink\Resolver\Ports\TrustAnchorBindingRepository;

final class SqliteL2Repository implements L2RecordRepository, L2StatementRepository, TrustAnchorBindingRepository
{
    private PDO $pdo;

    public function __construct(string $databasePath)
    {
        $this->pdo = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function storeBinding(TrustAnchorBinding $binding): void
    {
        // §16: bindings arrive through the policy/admin channel only; a duplicate anchor
        // binding is rejected fail-closed (a Trust Anchor Binding is a single policy fact).
        $statement = $this->pdo->prepare('INSERT INTO trust_anchor_bindings (anchor_id, entity_id, key_id, public_key, algorithm, initial_epoch_id, initial_statement_floor, created_at) VALUES (:anchor_id, :entity_id, :key_id, :public_key, :algorithm, :initial_epoch_id, :initial_statement_floor, CURRENT_TIMESTAMP)');
        try {
            $statement->execute([
                'anchor_id' => $binding->anchorId(),
                'entity_id' => $binding->entityId(),
                'key_id' => $binding->keyId(),
                'public_key' => $binding->publicKeyBase64Url(),
                'algorithm' => $binding->algorithm(),
                'initial_epoch_id' => $binding->initialEpochId(),
                'initial_statement_floor' => $binding->initialStatementFloor(),
            ]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000' && str_contains($error->getMessage(), 'UNIQUE constraint failed: trust_anchor_bindings.anchor_id')) {
                throw new RuntimeException('BINDING_EXISTS', 0, $error);
            }
            throw $error;
        }
    }

    /**
     * §9.4 fail-closed: the stored key_id column is passed verbatim to
     * TrustAnchorBinding::fromPolicyChannel, which recomputes keyId from the stored
     * public_key bytes and validates it. A stored key_id that does not equal the
     * §10.1 derivation of the stored publicKey fails the load closed.
     */
    public function findBinding(string $anchorId): ?TrustAnchorBinding
    {
        $statement = $this->pdo->prepare('SELECT anchor_id, entity_id, key_id, public_key, algorithm, initial_epoch_id, initial_statement_floor FROM trust_anchor_bindings WHERE anchor_id = :anchor_id');
        $statement->execute(['anchor_id' => $anchorId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return TrustAnchorBinding::fromPolicyChannel(
            (string) $row['anchor_id'],
            (string) $row['entity_id'],
            (string) $row['key_id'],
            (string) $row['public_key'],
            (string) $row['algorithm'],
            (string) $row['initial_epoch_id'],
            (int) $row['initial_statement_floor'],
        );
    }

    /** §9.5: the canonical wire payload is stored byte-for-byte; derived columns serve queries only. */
    public function appendRecord(Record $record, string $wire): void
    {
        // L2-SRV-002: record generations are unique per anchor; a duplicate append fails closed.
        $statement = $this->pdo->prepare('INSERT INTO l2_records (anchor_id, record_generation, wire, entity_id, signer_key_id, epoch_id, issued_at, expires_at, statement_floor, prev_record_digest, stored_at) VALUES (:anchor_id, :record_generation, :wire, :entity_id, :signer_key_id, :epoch_id, :issued_at, :expires_at, :statement_floor, :prev_record_digest, CURRENT_TIMESTAMP)');
        try {
            $statement->execute([
                'anchor_id' => $record->anchorId(),
                'record_generation' => $record->recordGeneration(),
                'wire' => $wire,
                'entity_id' => $record->entityId(),
                'signer_key_id' => $record->signerKeyId(),
                'epoch_id' => $record->epochId(),
                'issued_at' => $record->issuedAt(),
                'expires_at' => $record->expiresAt(),
                'statement_floor' => $record->statementFloor(),
                'prev_record_digest' => $record->prevRecordDigest(),
            ]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000' && str_contains($error->getMessage(), 'UNIQUE constraint failed: l2_records.anchor_id, l2_records.record_generation')) {
                throw new RuntimeException('RECORD_EXISTS', 0, $error);
            }
            throw $error;
        }
    }

    public function recordWires(string $anchorId): array
    {
        $statement = $this->pdo->prepare('SELECT wire FROM l2_records WHERE anchor_id = :anchor_id ORDER BY record_generation ASC');
        $statement->execute(['anchor_id' => $anchorId]);

        return array_map(static fn (array $row): string => (string) $row['wire'], $statement->fetchAll());
    }

    public function maxGeneration(string $anchorId): int
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(MAX(record_generation), -1) FROM l2_records WHERE anchor_id = :anchor_id');
        $statement->execute(['anchor_id' => $anchorId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * §6.3: Records 0..G must be strictly ascending and complete. A missing generation
     * between 0 and the max generation (including a missing Record 0) is a gap: the set
     * is returned as null so callers never serve it as complete.
     */
    public function contiguousRecordWires(string $anchorId): ?array
    {
        $statement = $this->pdo->prepare('SELECT record_generation, wire FROM l2_records WHERE anchor_id = :anchor_id ORDER BY record_generation ASC');
        $statement->execute(['anchor_id' => $anchorId]);
        $rows = $statement->fetchAll();
        $wires = [];
        $expected = 0;
        foreach ($rows as $row) {
            if ((int) $row['record_generation'] !== $expected) {
                return null;
            }
            $wires[] = (string) $row['wire'];
            $expected++;
        }

        return $wires === [] ? null : $wires;
    }

    public function expiresAtOfMaxGeneration(string $anchorId): ?int
    {
        $statement = $this->pdo->prepare('SELECT expires_at FROM l2_records WHERE anchor_id = :anchor_id ORDER BY record_generation DESC LIMIT 1');
        $statement->execute(['anchor_id' => $anchorId]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /** §7.3/§9.5 storage principle: statement wires are stored byte-for-byte as well. */
    public function appendStatement(StatementPayload $statementPayload, string $wire): void
    {
        $statement = $this->pdo->prepare('INSERT INTO l2_statements (anchor_id, key_id, generation, wire, issued_at, expires_at, stored_at) VALUES (:anchor_id, :key_id, :generation, :wire, :issued_at, :expires_at, CURRENT_TIMESTAMP)');
        try {
            $statement->execute([
                'anchor_id' => $statementPayload->anchorId(),
                'key_id' => $statementPayload->keyId(),
                'generation' => $statementPayload->generation(),
                'wire' => $wire,
                'issued_at' => $statementPayload->issuedAt(),
                'expires_at' => $statementPayload->expiresAt(),
            ]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000' && str_contains($error->getMessage(), 'UNIQUE constraint failed: l2_statements.anchor_id, l2_statements.key_id, l2_statements.generation')) {
                throw new RuntimeException('STATEMENT_EXISTS', 0, $error);
            }
            throw $error;
        }
    }

    public function statementWires(string $anchorId): array
    {
        // Deterministic storage order: ascending generation, ties ordered by key id.
        $statement = $this->pdo->prepare('SELECT wire FROM l2_statements WHERE anchor_id = :anchor_id ORDER BY generation ASC, key_id ASC');
        $statement->execute(['anchor_id' => $anchorId]);

        return array_map(static fn (array $row): string => (string) $row['wire'], $statement->fetchAll());
    }

    public function statementCount(string $anchorId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM l2_statements WHERE anchor_id = :anchor_id');
        $statement->execute(['anchor_id' => $anchorId]);

        return (int) $statement->fetchColumn();
    }
}
