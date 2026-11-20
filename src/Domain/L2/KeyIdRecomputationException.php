<?php
// src/Domain/L2/KeyIdRecomputationException.php
// §9.4 の keyId 再計算照合の失敗（wire keyId が publicKey から再計算された keyId と
// 一致しない）。chain walk はこの失敗を ChainStopKind::KEY_ID_RECOMPUTATION として
// 記録し、依存 statement は phase 5 で UNKNOWN_AUTHORITY になる（§15.1, §15.2 phase 5）。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class KeyIdRecomputationException extends PayloadStructureException
{
}
