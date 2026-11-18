<?php
// src/Ports/L2StatementRepository.php
// L2 Statement の永続化ポート（§6.3 保存スコープ、§9.5 と同じ verbatim 保存原則）。
// wire は byte-for-byte で保存し、public 公開面（GET /relink/{uuid}?l=2）は Statement を
// 提供しない。公開経路での書き込みは存在しない（§6.4/§16、格納は policy/admin 経路のみ）。

declare(strict_types=1);

namespace Relink\Resolver\Ports;

use Relink\Resolver\Domain\L2\StatementPayload;

interface L2StatementRepository
{
    /** Store the verbatim canonical wire payload for a resolution statement. */
    public function appendStatement(StatementPayload $statement, string $wire): void;

    /**
     * P1-1 (PR #31 review round 2, §7.2/§11.3): generations are intra-epoch sequences, so the
     * read side takes the epoch as an explicit typed selection. When $epochId is given, only
     * statements of that epoch are returned; the storage keeps the epoch explicit per row.
     *
     * @return list<string> verbatim wires, ascending by generation (ties ordered by key id)
     */
    public function statementWires(string $anchorId, ?string $epochId = null): array;

    public function statementCount(string $anchorId): int;
}
