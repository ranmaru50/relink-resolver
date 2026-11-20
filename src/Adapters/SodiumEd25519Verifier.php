<?php
// src/Adapters/SodiumEd25519Verifier.php
// libsodium による Ed25519 署名検証アダプタ（spec resolver-l2-0.1-draft §10.8, RFC 8032）。
// publicKey は 32 オクテット、signature は 64 オクテット、署名対象は JCS canonical payload。
// 未対応アルゴリズムは検証を試みない fail-closed（§10.8）。ES256 はこの Stage の対象外。

declare(strict_types=1);

namespace Relink\Resolver\Adapters;

use Relink\Resolver\Domain\L2\Base64Url;
use Relink\Resolver\Domain\L2\Base64UrlException;
use Relink\Resolver\Domain\L2\Verdict;
use Relink\Resolver\Ports\SignatureVerifier;

final class SodiumEd25519Verifier implements SignatureVerifier
{
    private const ALGORITHM_ED25519 = 'ed25519';

    public function supports(string $algorithm): bool
    {
        return $algorithm === self::ALGORITHM_ED25519;
    }

    public function verify(
        string $algorithm,
        string $publicKeyBase64Url,
        string $signatureBase64Url,
        string $signingTargetOctets,
    ): Verdict {
        if (!$this->supports($algorithm)) {
            // §10.8: 未対応アルゴリズムは検証を試みない
            return Verdict::UNSUPPORTED_ALGORITHM;
        }

        try {
            $publicKey = Base64Url::decode($publicKeyBase64Url);
            $signature = Base64Url::decode($signatureBase64Url);
        } catch (Base64UrlException) {
            return Verdict::INVALID;
        }

        if (strlen($publicKey) !== 32) {
            return Verdict::INVALID;
        }
        if (strlen($signature) !== 64) {
            return Verdict::INVALID;
        }

        try {
            $ok = sodium_crypto_sign_verify_detached($signature, $signingTargetOctets, $publicKey);
        } catch (\SodiumException) {
            return Verdict::INVALID;
        }

        return $ok ? Verdict::VERIFIED : Verdict::INVALID;
    }
}
