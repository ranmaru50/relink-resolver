<?php
// tests/HttpRedirectResolverTest.php
// 管理 outbound fetch の RFC 3986 relative-reference 解決を検証する。

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Relink\Resolver\Adapters\HttpRedirectResolver;

final class HttpRedirectResolverTest extends TestCase
{
    /** absolute、origin-relative、path-relative、親ディレクトリを解決する。 */
    public function testResolvesAbsoluteAndRelativeReferences(): void
    {
        $base = 'https://example.com/a/b/entity.xml';

        self::assertSame('https://cdn.example/x.xml', HttpRedirectResolver::resolve($base, 'https://cdn.example/x.xml'));
        self::assertSame('https://example.com/x.xml', HttpRedirectResolver::resolve($base, '/x.xml'));
        self::assertSame('https://example.com/a/b/next.xml', HttpRedirectResolver::resolve($base, 'next.xml'));
        self::assertSame('https://example.com/a/next.xml', HttpRedirectResolver::resolve($base, '../next.xml'));
        self::assertSame('https://cdn.example/x.xml', HttpRedirectResolver::resolve($base, '//cdn.example/x.xml'));
    }

    /** query-only と fragment を HTTP request-target の semantics に従って処理する。 */
    public function testPreservesOrReplacesQueryAndDropsFragment(): void
    {
        $base = 'https://example.com/a/entity.xml?old=1';

        self::assertSame('https://example.com/a/entity.xml?new=2', HttpRedirectResolver::resolve($base, '?new=2#fragment'));
        self::assertSame('https://example.com/a/entity.xml?old=1', HttpRedirectResolver::resolve($base, '#fragment'));
        self::assertSame('https://example.com/a/entity.xml?old=1', HttpRedirectResolver::resolve($base, ''));
    }
}
