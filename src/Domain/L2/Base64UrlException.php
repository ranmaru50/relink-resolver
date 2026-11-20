<?php
// src/Domain/L2/Base64UrlException.php
// base64url  decoding が失敗したことを示すドメイン例外。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class Base64UrlException extends \RuntimeException
{
}
