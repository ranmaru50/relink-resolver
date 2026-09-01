<?php
// tests/DomainValueObjectTest.php
// ドメイン値オブジェクトと公開解決結果の境界条件を検証する。

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Relink\Resolver\Domain\AnchorUuid;
use Relink\Resolver\Domain\DescriptionLocation;
use Relink\Resolver\Domain\LifecycleState;
use Relink\Resolver\Domain\ResolutionResult;

final class DomainValueObjectTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function validUuidProvider(): iterable
    {
        yield 'lowercase' => ['550e8400-e29b-41d4-a716-446655440000', '550e8400-e29b-41d4-a716-446655440000'];
        yield 'uppercase is normalized' => ['550E8400-E29B-41D4-A716-446655440000', '550e8400-e29b-41d4-a716-446655440000'];
        yield 'surrounding whitespace is trimmed' => [' 550e8400-e29b-41d4-a716-446655440000 ', '550e8400-e29b-41d4-a716-446655440000'];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUuidProvider(): iterable
    {
        yield 'missing segment' => ['550e8400-e29b-41d4-a716-44665544000'];
        yield 'non hexadecimal character' => ['550e8400-e29b-41d4-a716-44665544000z'];
        yield 'braced form' => ['{550e8400-e29b-41d4-a716-446655440000}'];
    }

    /** 正規化後の UUID が値オブジェクトに保持されることを確認する。 */
    #[DataProvider('validUuidProvider')]
    public function testAnchorUuidNormalizesAcceptedForms(string $input, string $expected): void
    {
        $anchor = new AnchorUuid($input);

        $this->assertSame($expected, $anchor->value);
        $this->assertSame($expected, (string) $anchor);
    }

    /** 不正な UUID を受け付けないことを確認する。 */
    #[DataProvider('invalidUuidProvider')]
    public function testAnchorUuidRejectsMalformedForms(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AnchorUuid($input);
    }

    /** HTTPS の絶対 URI が保存時に正規化されることを確認する。 */
    public function testDescriptionLocationAcceptsHttpsAbsoluteUri(): void
    {
        $location = new DescriptionLocation(' https://entity.example/arxml.xml?rev=1 ');

        $this->assertSame('https://entity.example/arxml.xml?rev=1', $location->value);
        $this->assertSame($location->value, (string) $location);
    }

    /** L1 の Location として危険または不完全な URI を拒否することを確認する。 */
    #[DataProvider('invalidLocationProvider')]
    public function testDescriptionLocationRejectsNonHttpsOrUnsafeUri(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DescriptionLocation($input);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLocationProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'relative path' => ['/arxml.xml'];
        yield 'http scheme' => ['http://entity.example/arxml.xml'];
        yield 'header injection' => ["https://entity.example/arxml.xml\nX-Test: injected"];
    }

    /** ライフサイクル入力の大文字化・空白除去と公開値を確認する。 */
    public function testLifecycleStateParsesInputAndManifestValue(): void
    {
        $state = LifecycleState::fromInput(' suspended ');

        $this->assertSame(LifecycleState::SUSPENDED, $state);
        $this->assertSame('suspended', $state->manifestValue());
    }

    /** 未定義のライフサイクル状態を拒否することを確認する。 */
    public function testLifecycleStateRejectsUnknownInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LifecycleState::fromInput('DELETED');
    }

    /** リダイレクト結果のステータス、Location、負値の max-age 補正を確認する。 */
    public function testResolutionResultRedirectClampsNegativeCacheAge(): void
    {
        $result = ResolutionResult::redirect('https://entity.example/arxml.xml', -1);

        $this->assertSame(303, $result->status);
        $this->assertSame('https://entity.example/arxml.xml', $result->headers['Location']);
        $this->assertSame('public, max-age=0', $result->headers['Cache-Control']);
    }

    /** Core のエラー種別ごとのキャッシュ方針を確認する。 */
    public function testResolutionResultErrorCachePolicy(): void
    {
        foreach ([400, 404, 500, 501, 503] as $status) {
            $this->assertSame('no-store', ResolutionResult::error($status)->headers['Cache-Control']);
        }
        $this->assertSame('public, max-age=300', ResolutionResult::error(410)->headers['Cache-Control']);
    }
}
