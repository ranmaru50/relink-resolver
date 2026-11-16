<?php
// src/Ports/L2RecordRepository.php
// Authority Record の永続化ポート（§9.5/§6.3）。Append は canonical wire を byte-for-byte で
// 保存し、読み出しは anchor ごとの generation 昇順。0..G の連続性はギャップ検出の必須条件
// （§6.3: verifier は 0..G を全て必要とし、ギャップは UNVERIFIED。公開エンドポイントは
// ギャップのある集合を完全な集合として提供してはならない）。

declare(strict_types=1);

namespace Relink\Resolver\Ports;

use Relink\Resolver\Domain\L2\Record;

interface L2RecordRepository
{
    /**
     * Append the verbatim canonical wire payload for the Record (§9.5).
     * A duplicate record generation for the same anchor MUST fail closed.
     */
    public function appendRecord(Record $record, string $wire): void;

    /** @return list<string> verbatim wires, ascending by record generation */
    public function recordWires(string $anchorId): array;

    /** @return int max record generation for the anchor, -1 when the anchor has no Records */
    public function maxGeneration(string $anchorId): int;

    /**
     * §6.3: return the complete contiguous 0..G prefix, or null when the stored set
     * has a gap between 0 and the max generation (including a missing Record 0).
     *
     * @return ?list<string> verbatim wires ascending 0..G, null on gap
     */
    public function contiguousRecordWires(string $anchorId): ?array;

    /** @return ?int expiresAt of Record G (the max generation), null when the set is empty (§14.2 input) */
    public function expiresAtOfMaxGeneration(string $anchorId): ?int;
}
