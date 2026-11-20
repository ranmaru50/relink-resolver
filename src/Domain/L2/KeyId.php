<?php
// src/Domain/L2/KeyId.php
// keyId 規則（spec resolver-l2-0.1-draft §10.1, §9.4）。
// keyId = SHA-256(publicKey 生オクテット) の先頭 8 バイトの
// base64url（'=' 除去）。大文字小文字は保存する。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final class KeyId
{
    /**
     * publicKey 生オクテットから keyId を再計算する（§9.4 fail-closed 照合用）。
     */
    public static function compute(string $publicKeyOctets): string
    {
        $digest = hash('sha256', $publicKeyOctets, true);

        return Base64Url::encode(substr($digest, 0, 8));
    }

    /**
     * wire の keyId と publicKey から再計算した keyId が一致するか（大文字小文字保存）。
     */
    public static function matches(string $wireKeyId, string $publicKeyOctets): bool
    {
        return $wireKeyId === self::compute($publicKeyOctets);
    }
}
