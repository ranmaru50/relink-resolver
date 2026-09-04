<?php
// src/Ports/AdminCredentialVerifier.php
// 管理ホストが提供する資格情報検証のポート。

declare(strict_types=1);

namespace Relink\Resolver\Ports;

interface AdminCredentialVerifier
{
    /**
     * 入力された管理資格情報を検証する。
     *
     * 認証方式、パスワードハッシュ、SSO/RBAC などの詳細はホスト側が決定する。
     */
    public function verify(string $username, string $password): bool;
}
