<?php
// tests/AdminPresentationTest.php
// 管理画面のルーティング・セキュリティ境界・アクセシブルな表示構造を確認する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminPresentationTest extends TestCase
{
    private string $adminSource;
    private string $stylesheet;

    protected function setUp(): void
    {
        $admin = file_get_contents(dirname(__DIR__) . '/public/admin.php');
        $css = file_get_contents(dirname(__DIR__) . '/public/assets/admin.css');
        self::assertNotFalse($admin);
        self::assertNotFalse($css);
        $this->adminSource = $admin;
        $this->stylesheet = $css;
    }

    /** ログイン、ナビゲーション、業務別ページの構造が存在することを確認する。 */
    public function testAdminUsesSeparateAccessibleWorkflows(): void
    {
        self::assertStringContainsString('$route = strtolower((string) ($_GET[\'route\'] ?? \'overview\'))', $this->adminSource);
        self::assertStringContainsString('admin_render_login', $this->adminSource);
        self::assertStringContainsString('admin_render_records', $this->adminSource);
        self::assertStringContainsString('admin_render_record_detail', $this->adminSource);
        self::assertStringContainsString('AdminTranslator', $this->adminSource);
        self::assertStringContainsString('admin_render_language_switcher', $this->adminSource);
        self::assertStringContainsString('name="lang"', $this->adminSource);
        self::assertStringContainsString("\$_SESSION['admin_locale']", $this->adminSource);
        self::assertStringContainsString('Relink\\Resolver\\Hosting\\AdminTranslator', $this->adminSource);
        self::assertStringNotContainsString('Relink\\Resolver\\Application\\AdminTranslator', $this->adminSource);
        self::assertStringContainsString('<html lang="', $this->adminSource);
        self::assertStringContainsString('scope="col"', $this->adminSource);
        self::assertStringContainsString('meta name="viewport"', $this->adminSource);
    }

    /** GET表示とPOST変更の境界、retired確認、既存JSON APIの境界を維持する。 */
    public function testAdminKeepsMutationAndSecurityBoundaries(): void
    {
        self::assertStringContainsString("style-src 'self'", $this->adminSource);
        self::assertStringContainsString('!hash_equals($csrf', $this->adminSource);
        self::assertStringContainsString('strtoupper((string) ($_SERVER[\'REQUEST_METHOD\'] ?? \'GET\')) !== \'POST\'', $this->adminSource);
        self::assertStringContainsString('name="confirm_retire"', $this->adminSource);
        self::assertStringContainsString('format', $this->adminSource);
        self::assertStringContainsString('Cache-Control', $this->adminSource);
        self::assertStringNotContainsString('<script', $this->adminSource);
    }

    /** ローカルCSSがレスポンシブ表示とキーボード操作を定義する。 */
    public function testLocalStylesheetProvidesResponsiveAccessiblePresentation(): void
    {
        self::assertStringContainsString('focus-visible', $this->stylesheet);
        self::assertStringContainsString('@media (max-width: 820px)', $this->stylesheet);
        self::assertStringContainsString('.status-badge', $this->stylesheet);
        self::assertStringContainsString('.button-danger', $this->stylesheet);
        self::assertStringContainsString('.breakable', $this->stylesheet);
    }

    /** #14のManifest公開操作がレコード詳細画面のPOST経路から利用できることを確認する。 */
    public function testAdminPreservesManifestPublicationWorkflows(): void
    {
        foreach (['publication', 'calculate-integrity', 'manifest-preview'] as $action) {
            self::assertStringContainsString('value="' . $action . '"', $this->adminSource);
        }
        foreach (['direct', 'without-integrity', 'supplied', 'calculated'] as $mode) {
            self::assertStringContainsString('value="' . $mode . '"', $this->adminSource);
        }
        self::assertStringContainsString('configureManifest(', $this->adminSource);
        self::assertStringContainsString('calculateAndPinIntegrity(', $this->adminSource);
        self::assertStringContainsString('previewManifest(', $this->adminSource);
        self::assertStringContainsString('new NativeAdministrativeResourceFetcher(', $this->adminSource);
        self::assertStringContainsString("if (strtoupper((string) (\$_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'", $this->adminSource);
    }
}
