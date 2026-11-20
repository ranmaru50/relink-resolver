<?php
// src/Ports/SignatureVerifier.php
// 署名検証ポート（spec resolver-l2-0.1-draft §10.8, §15）。
// algorithm agility: 対象化された署名アルゴリズム集合のみ検証する。
// 未知/未対応アルゴリズムは検証を試みず UNSUPPORTED_ALGORITHM を返す（fail-closed）。

declare(strict_types=1);

namespace Relink\Resolver\Ports;

use Relink\Resolver\Domain\L2\Verdict;

interface SignatureVerifier
{
    /**
     * この verifiers が署名アルゴリズム文字列をサポートするか。
     * サポートしないアルゴリズムは verify() で検証を試みない。
     */
    public function supports(string $algorithm): bool;

    /**
     * base64url（'=' 除去）の publicKey と signature を、署名対象オクテットに対して検証する。
     *
     * @param string $algorithm wire payload の algorithm 文字列
     * @param string $publicKeyBase64Url wire publicKey（base64url, '=' 除去）
     * @param string $signatureBase64Url wire signature（base64url, '=' 除去）
     * @param string $signingTargetOctets JCS canonical payload の UTF-8 オクテット
     *
     * @return Verdict VERIFIED / INVALID / UNSUPPORTED_ALGORITHM
     */
    public function verify(
        string $algorithm,
        string $publicKeyBase64Url,
        string $signatureBase64Url,
        string $signingTargetOctets,
    ): Verdict;
}
