<?php
// src/Domain/L2/CanonicalizationException.php
// 正準化（JCS）が対象化できない入力であることを示すドメイン例外。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class CanonicalizationException extends \RuntimeException
{
}
