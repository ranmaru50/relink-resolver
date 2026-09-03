<?php
// src/Application/AdminRequestException.php
// 管理リクエストの入力不備を安全なHTTPステータスへ変換する例外。

declare(strict_types=1);

namespace Relink\Resolver\Application;

use RuntimeException;

final class AdminRequestException extends RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct($status === 413 ? 'Request entity too large' : 'Invalid request');
    }
}
