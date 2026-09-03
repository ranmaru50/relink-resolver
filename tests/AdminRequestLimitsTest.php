<?php
// tests/AdminRequestLimitsTest.php
// 管理面の入力上限、過大本文、検索正規化、ページング境界を検証する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Application\AdminRequestGuard;
use Relink\Resolver\Application\AdminRequestException;
use Relink\Resolver\Application\AdminRequestLimits;

final class AdminRequestLimitsTest extends TestCase
{
    private function guard(): AdminRequestGuard
    {
        return new AdminRequestGuard(new AdminRequestLimits());
    }

    /** Content-Lengthが本文上限を超えた場合は処理前に413へ分類する。 */
    public function testContentLengthOverLimitIs413(): void
    {
        $this->expectException(AdminRequestException::class);
        $this->expectExceptionMessage('Request entity too large');
        $this->guard()->assertContentLength('65537');
    }

    /** Content-Lengthの不正値は内部情報を含まない400へ分類する。 */
    public function testMalformedContentLengthIs400(): void
    {
        try {
            $this->guard()->assertContentLength('not-a-length');
            $this->fail('不正なContent-Lengthが受理されました。');
        } catch (AdminRequestException $error) {
            $this->assertSame(400, $error->status);
            $this->assertSame('Invalid request', $error->getMessage());
        }
    }

    /** Location、理由、検索語の境界値を受理し、超過値を安全な400で拒否する。 */
    public function testFieldLengthBoundaries(): void
    {
        $guard = $this->guard();
        $guard->assertPost([
            'uuid' => str_repeat('a', 36),
            'location' => str_repeat('a', 2048),
            'entity_id' => str_repeat('a', 2048),
            'reason' => str_repeat('a', 500),
        ]);
        $guard->assertQuery(['q' => str_repeat('A', 200)]);

        try {
            $guard->assertPost(['reason' => str_repeat('a', 501)]);
            $this->fail('上限超過の理由が受理されました。');
        } catch (AdminRequestException $error) {
            $this->assertSame(400, $error->status);
        }

        try {
            $guard->assertQuery(['q' => str_repeat('A', 201)]);
            $this->fail('上限超過の検索語が受理されました。');
        } catch (AdminRequestException $error) {
            $this->assertSame(400, $error->status);
        }
    }

    /** 配列パラメータと過大なPOST変数数を拒否する。 */
    public function testNestedInputAndTooManyVariablesAreRejected(): void
    {
        try {
            $this->guard()->assertPost(['uuid' => ['nested']]);
            $this->fail('配列形式のPOST値が受理されました。');
        } catch (AdminRequestException $error) {
            $this->assertSame(400, $error->status);
        }

        $tooMany = array_fill_keys(array_map(static fn (int $index): string => 'field' . $index, range(1, 33)), 'x');
        $this->expectException(AdminRequestException::class);
        $this->guard()->assertPost($tooMany);
    }

    /** 検索語を正規化し、ページ番号と件数を安全な範囲へ制限する。 */
    public function testListQueryNormalizesAndBoundsPagination(): void
    {
        $query = $this->guard()->listQuery(['q' => '  ENTITY/42  ', 'page' => '2', 'per_page' => '50']);

        $this->assertSame('entity/42', $query->needle);
        $this->assertSame(2, $query->page);
        $this->assertSame(50, $query->perPage);
        $this->assertSame(50, $query->offset());

        $this->expectException(AdminRequestException::class);
        $this->guard()->listQuery(['page' => '10001']);
    }
}
