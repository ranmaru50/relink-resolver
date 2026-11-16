<?php
// src/Domain/L2/PayloadStructureException.php
// payload メンバ集合と型検証の構造失敗（spec resolver-l2-0.1-draft §7.2, §9.5, §12.2,
// §15.2 phase 1 の INVALID）。wire envelope の strict parse 失敗は Stage 1 の
// WireStatementException が担う。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class PayloadStructureException extends \RuntimeException
{
    public function verdict(): Verdict
    {
        return Verdict::INVALID;
    }
}
