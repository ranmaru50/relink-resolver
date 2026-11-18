<?php
// src/Domain/L2/ChainStopKind.php
// chain walk 停止の種別（spec resolver-l2-0.1-draft §9.4, §9.6, §15.2 phase 5）。
// 停止種別は語彙として記録され、文書文字列の分類で判定しない。WIRE_PARSE は phase 1 の
// wire / payload 構造失敗。KEY_ID_RECOMPUTATION は §9.4 の keyId 再計算失敗で、
// 依存 statement は phase 5 で UNKNOWN_AUTHORITY になる（§15.1）。CHAIN は受理連鎖の
// 停止（contiguity gap, trail 検証失敗, mutation 規則, 位置規則）。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

enum ChainStopKind: string
{
    case WIRE_PARSE = 'wire-parse';
    case KEY_ID_RECOMPUTATION = 'key-id-recomputation';
    case CHAIN = 'chain';
}
