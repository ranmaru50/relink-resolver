<?php
// src/Application/ApplicationException.php
// 管理操作の境界で安全に扱えるエラーコードを保持する例外。

declare(strict_types=1);

namespace Relink\Resolver\Application;

use RuntimeException;

final class ApplicationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : $errorCode, $code, $previous);
    }
}
