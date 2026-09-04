<?php
// tests/AdminTranslatorTest.php
// 管理画面のロケール選択と翻訳カタログの基本動作を確認する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Hosting\AdminTranslator;

final class AdminTranslatorTest extends TestCase
{
    /** 明示ロケールとAccept-Languageから対応ロケールを選択する。 */
    public function testLocaleSelectionIsRestrictedToSupportedLocales(): void
    {
        self::assertSame('en', AdminTranslator::fromInput('en')->locale);
        self::assertSame('en', AdminTranslator::fromInput(null, 'en-US,en;q=0.9')->locale);
        self::assertSame('ja', AdminTranslator::fromInput('fr', 'fr-FR')->locale);
    }

    /** 日本語と英語の同一キーがそれぞれの翻訳を返す。 */
    public function testTranslationCatalogProvidesJapaneseAndEnglishText(): void
    {
        $japanese = new AdminTranslator('ja');
        $english = new AdminTranslator('en');

        self::assertSame('管理画面にログイン', $japanese->get('login.heading'));
        self::assertSame('Sign in to Admin', $english->get('login.heading'));
        self::assertSame('表示言語', $japanese->get('locale.switcher_label'));
        self::assertSame('Language', $english->get('locale.switcher_label'));
        self::assertSame('Page %d · up to %d', $english->get('records.page_summary'));
    }
}
