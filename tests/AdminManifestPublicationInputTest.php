<?php
// tests/AdminManifestPublicationInputTest.php
// Manifest公開設定の管理画面入力が公開モードごとに正規化されることを確認する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Hosting\AdminManifestPublicationInput;

final class AdminManifestPublicationInputTest extends TestCase
{
    /** 非suppliedモードでは、フォームに残った古い整合性情報を破棄する。 */
    public function testNonSuppliedModesDiscardStaleIntegrity(): void
    {
        foreach (['direct', 'without-integrity', 'calculated'] as $mode) {
            $input = AdminManifestPublicationInput::fromPost([
                'publication_mode' => $mode,
                'integrity_algorithm' => 'sha-256',
                'integrity_digest' => str_repeat('a', 64),
            ]);

            self::assertNull($input->algorithm, $mode);
            self::assertNull($input->digest, $mode);
        }
    }

    /** suppliedモードでは、入力された整合性情報を業務サービスへ渡す。 */
    public function testSuppliedModePreservesIntegrity(): void
    {
        $input = AdminManifestPublicationInput::fromPost([
            'publication_mode' => 'supplied',
            'integrity_algorithm' => 'sha-256',
            'integrity_digest' => str_repeat('b', 64),
        ]);

        self::assertSame('sha-256', $input->algorithm);
        self::assertSame(str_repeat('b', 64), $input->digest);
    }
}
