<?php
// src/Domain/L2/WireStatementException.php
// wire 文が wire パース/正準性/整数域の phase-1 検証で拒否されたことを示すドメイン例外。
// 判定は常に INVALID（spec resolver-l2-0.1-draft §7.2, §15.2 phase 1）。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class WireStatementException extends \RuntimeException
{
    public function verdict(): Verdict
    {
        return Verdict::INVALID;
    }
}
