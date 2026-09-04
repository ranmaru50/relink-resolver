<?php
// src/Adapters/ConfiguredAdminCredentialVerifier.php
// Composition root から受け取った管理資格情報を検証する標準アダプタ。

declare(strict_types=1);

namespace Relink\Resolver\Adapters;

use Relink\Resolver\Ports\AdminCredentialVerifier;

final readonly class ConfiguredAdminCredentialVerifier implements AdminCredentialVerifier
{
    public function __construct(
        private string $username,
        private string $password,
    ) {
    }

    /** パスワードハッシュと従来の固定シークレットを両方検証する。 */
    public function verify(string $username, string $password): bool
    {
        if ($username !== $this->username || $this->password === '') {
            return false;
        }
        if (password_get_info($this->password)['algo'] !== null) {
            return password_verify($password, $this->password);
        }
        return hash_equals($this->password, $password);
    }
}
