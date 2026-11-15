<?php
// tests/L2/Stage1JcsCanonicalizationTest.php
// RFC 8785 JCS 正準化のテスト（spec resolver-l2-0.1-draft §7.3、付録 A.2/A.3/A.4）。
// 付録 A と Conformance Catalog §10 の pinned canonical string を verbatim で主張する。
// カバレッジ対象: L2-PRO（正準シリアライズ・署名バイト）、wire 正準性は L2-VER-046。

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Relink\Resolver\Domain\L2\CanonicalizationException;
use Relink\Resolver\Domain\L2\JcsCanonicalizer;

require_once __DIR__ . '/L2Fixtures.php';

final class Stage1JcsCanonicalizationTest extends TestCase
{
    /**
     * @return Generator<array{string, string}>
     */
    public static function canonicalVectorProvider(): Generator
    {
        foreach (L2Fixtures::signedVectors() as $vector) {
            yield [$vector['id'], $vector['canonical']];
        }
    }

    /**
     * pinned canonical string を json_decode → canonicalize すると verbatim に一致する。
     * 該当: 付録 A.2 (Vector1), A.3 (Vector2), A.4 (Vector3/V3b), Record0..6,
     * Trail2/5/6, S5..S8, Record4A..5V, S9 family (Catalog §10)。
     */
    #[DataProvider('canonicalVectorProvider')]
    public function testPinnedCanonicalStringsSurviveCanonicalRoundTrip(string $id, string $canonical): void
    {
        $decoded = json_decode($canonical, true);
        $this->assertIsArray($decoded, $id . ' must decode to an object');

        // canonicalize(json_decode(canonical)) === pinned canonical（再計算しない）
        $this->assertSame($canonical, JcsCanonicalizer::canonicalize($decoded), $id);
    }

    /**
     * A.4: object メンバ名は UTF-16 code unit 順でソートする。
     * スカラー code point 順（U+E000 < U+1F600）では A.4 の pinned 文字列と異なる。
     */
    public function testNonBmpKeysSortByUtf16CodeUnitOrderNotCodePointOrder(): void
    {
        $scrambled = [
            "\u{E000}" => 4,
            'z' => 1,
            "\u{1F600}" => 3,
            'a' => 1,
            "\u{00E9}" => 2,
        ];

        // pinned A.4 order: a(0061) < z(007A) < U+00E9 < U+1F600(D83D DE00) < U+E000
        $pinnedOrder = '{"a":1,"z":1,"' . "\u{00E9}" . '":2,"' . "\u{1F600}" . '":3,"' . "\u{E000}" . '":4}';
        $this->assertSame($pinnedOrder, JcsCanonicalizer::canonicalize($scrambled));

        // A.4 記載: scalar code point 順（57344 < 128512）は別の文字列になりこのベクトルに失敗する
        $scalarOrderString = '{"a":1,"z":1,"' . "\u{00E9}" . '":2,"' . "\u{E000}" . '":4,"' . "\u{1F600}" . '":3}';
        $this->assertNotSame($pinnedOrder, $scalarOrderString, 'scalar code-point order must differ from UTF-16 code-unit order (A.4)');
    }

    /**
     * A.4 Vector3: extUnicode を scrambled 順で構築しても pinned canonical に一致する。
     */
    public function testVector3PinnedCanonicalIncludesUtf16OrderedExtUnicode(): void
    {
        $payload = [
            'algorithm' => 'ed25519',
            'anchorId' => '550e8400-e29b-41d4-a716-446655440000',
            'descriptionLocation' => 'https://sensors.example/thermal/42/ar-xml',
            'entityId' => 'urn:relink:entity:thermal-sensor-42',
            'epochId' => 'epoch-0',
            'expiresAt' => 1770000000,
            'extExample' => ['z' => 1, 'a' => [2, 3]],
            'extUnicode' => ["\u{E000}" => 4, 'z' => 1, "\u{1F600}" => 3, 'a' => 1, "\u{00E9}" => 2],
            'generation' => 7,
            'issuedAt' => 1769996400,
            'keyId' => 'ECMERdvpV0k',
            'l2Version' => '0.1',
            'lifecycleStatus' => 'active',
            'statementType' => 'resolution',
        ];

        $this->assertSame(L2Fixtures::signedVectors()[2]['canonical'], JcsCanonicalizer::canonicalize($payload));
    }

    /**
     * V3b (A.4): U+2028 / U+2029 / U+007F は raw で出力、tab は \t にエスケープする。
     * （§7.3 / RFC 8785 §3.2.2.2）
     */
    public function testV3bEscapesTabButKeepsU2028U2029U007FRaw(): void
    {
        $payload = [
            'algorithm' => 'ed25519',
            'anchorId' => '550e8400-e29b-41d4-a716-446655440000',
            'descriptionLocation' => 'https://sensors.example/thermal/42/ar-xml',
            'entityId' => 'urn:relink:entity:thermal-sensor-42',
            'epochId' => 'epoch-0',
            'expiresAt' => 1770000900,
            'generation' => 2,
            'issuedAt' => 1769997300,
            'keyId' => 'ECMERdvpV0k',
            'l2Version' => '0.1',
            'lifecycleStatus' => 'active',
            'note' => "lineA\u{2028}lineB\u{2029}del\u{007F}tab\tend",
            'statementType' => 'resolution',
        ];

        $this->assertSame(L2Fixtures::signedVectors()[3]['canonical'], JcsCanonicalizer::canonicalize($payload));
    }

    /**
     * §3.2.2.2 エスケープ: " と \ は短縮形、C0 は \b \t \n \f \r の短縮形、
     * それ以外の C0 は小文字 hex の \uXXXX、printable な非 ASCII と raw U+2028/2029/007F は raw。
     *
     * @return Generator<array{string, string}>
     */
    public static function escapeProvider(): Generator
    {
        yield ["\x00", '"\u0000"'];
        yield ["\x01", '"\u0001"'];
        yield ["\x08", '"\b"'];
        yield ["\x09", '"\t"'];
        yield ["\x0A", '"\n"'];
        yield ["\x0B", '"\u000b"'];
        yield ["\x0C", '"\f"'];
        yield ["\x0D", '"\r"'];
        yield ["\x1F", '"\u001f"'];
        yield ['"', '"\""'];
        yield ['\\', '"\\\\"'];
        yield ["\x7F", '"' . "\x7F" . '"'];
        yield ["\u{2028}", '"' . "\u{2028}" . '"'];
        yield ["\u{2029}", '"' . "\u{2029}" . '"'];
        yield ["\u{00E9}", '"' . "\u{00E9}" . '"'];
        yield ["\u{1F600}", '"' . "\u{1F600}" . '"'];
    }

    #[DataProvider('escapeProvider')]
    public function testMinimalEscapingPerRfc8785(string $input, string $expected): void
    {
        $this->assertSame($expected, JcsCanonicalizer::canonicalize($input));
    }

    /**
     * ES2026 Number toString の最短表現（§7.3）。
     *
     * @return Generator<array{int|float, string}>
     */
    public static function numberProvider(): Generator
    {
        yield [1.0, '1'];
        yield [0.0, '0'];
        yield [-0.0, '0'];
        yield [42.0, '42'];
        yield [0.0001, '0.0001'];
        yield [0.001, '0.001'];
        yield [1e-5, '1e-5'];
        yield [1e-7, '1e-7'];
        yield [1e20, '100000000000000000000'];
        yield [1e21, '1e+21'];
        yield [1.23456789e17, '123456789000000000'];
        yield [0.123, '0.123'];
        yield [-1.5, '-1.5'];
        yield [3.141592653589793, '3.141592653589793'];
        yield [7, '7'];
        yield [-1, '-1'];
        yield [PHP_INT_MAX, (string) PHP_INT_MAX];
    }

    #[DataProvider('numberProvider')]
    public function testNumbersUseShortestEs262Form(int|float $input, string $expected): void
    {
        $this->assertSame($expected, JcsCanonicalizer::canonicalize($input));
    }

    public function testInfinityIsRejectedFailClosed(): void
    {
        $this->expectException(CanonicalizationException::class);
        JcsCanonicalizer::canonicalize(INF);
    }

    public function testNaNIsRejectedFailClosed(): void
    {
        $this->expectException(CanonicalizationException::class);
        JcsCanonicalizer::canonicalize(NAN);
    }

    public function testLiteralsListsAndNestedObjectsAreCanonicalized(): void
    {
        $this->assertSame('true', JcsCanonicalizer::canonicalize(true));
        $this->assertSame('false', JcsCanonicalizer::canonicalize(false));
        $this->assertSame('null', JcsCanonicalizer::canonicalize(null));
        $this->assertSame('[2,3]', JcsCanonicalizer::canonicalize([2, 3]));
        $this->assertSame('[]', JcsCanonicalizer::canonicalize([]));
        $this->assertSame('{"a":2,"b":1}', JcsCanonicalizer::canonicalize(['b' => 1, 'a' => 2]));
        // 入れ子 object は再帰的に正準化される（member 名は UTF-16 code unit 順）
        $this->assertSame('{"b":{"c":[1,2],"d":4}}', JcsCanonicalizer::canonicalize(['b' => ['d' => 4, 'c' => [1, 2]]]));
    }

    public function testPhpJsonDecodeMakesEmptyObjectAmbiguousAndFailClosed(): void
    {
        // 既知の spec 実装Ambiguity（Issue 報告済み）: PHP は json_decode('{}') を [] にする。
        // canonicalize は [] を "[]" にする為、wire 上の `{}` payload は正準性照合で fail-closed となる。
        // deviate せず fail-closed を検証する（AGENTS §14）。
        $decoded = json_decode('{}', true);
        $this->assertSame('[]', JcsCanonicalizer::canonicalize($decoded));
    }
}
