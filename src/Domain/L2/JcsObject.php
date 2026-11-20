<?php
// src/Domain/L2/JcsObject.php
// JSON オブジェクトのマーカー値（spec resolver-l2-0.1-draft §7.3, §7.5）。
// PHP の連想配列は JSON の {} と [] を区別できない。wire パースはオブジェクトノードを
// この型で保持し、正準化時に {} を [] と混同しない（空オブジェクトは正準形 {}）。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class JcsObject
{
    /**
     * @param array<string, mixed> $members メンバ名 => 値（JcsObject / list / scalar）
     */
    public function __construct(public readonly array $members)
    {
    }
}
