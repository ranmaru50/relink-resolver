<?php
// src/Adapters/HttpRedirectResolver.php
// RFC 3986 の relative-reference を HTTPS HTTP redirect 用 URL へ解決する。

declare(strict_types=1);

namespace Relink\Resolver\Adapters;

use RuntimeException;

final class HttpRedirectResolver
{
    public static function resolve(string $current, string $reference): string
    {
        // fragment は HTTP request-target ではないため、解決前に除去する。
        $reference = explode('#', $reference, 2)[0];
        $base = parse_url($current);
        $ref = $reference === '' ? [] : parse_url($reference);
        if (!is_array($base) || !is_array($ref) || !isset($base['scheme'], $base['host'])) {
            throw new RuntimeException('OUTBOUND_REDIRECT_INVALID');
        }
        if (isset($ref['scheme'])) {
            return $reference;
        }
        $scheme = (string) $base['scheme'];
        if (isset($ref['host'])) {
            return $scheme . '://' . self::authority($ref) . self::target($ref);
        }

        $basePath = (string) ($base['path'] ?? '/');
        $referencePath = (string) ($ref['path'] ?? '');
        $hasQuery = str_contains($reference, '?');
        if ($referencePath === '') {
            $path = $basePath;
            $query = $hasQuery ? (string) ($ref['query'] ?? '') : ($base['query'] ?? null);
        } else {
            $directory = str_ends_with($basePath, '/') ? $basePath : substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
            $path = str_starts_with($referencePath, '/') ? $referencePath : $directory . $referencePath;
            $path = self::removeDotSegments($path);
            $query = $ref['query'] ?? null;
        }
        $url = $scheme . '://' . self::authority($base) . $path;
        return $query === null ? $url : $url . '?' . $query;
    }

    /** @param array<string, mixed> $parts */
    private static function authority(array $parts): string
    {
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('OUTBOUND_REDIRECT_INVALID');
        }
        $host = (string) $parts['host'];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        return $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    /** @param array<string, mixed> $parts */
    private static function target(array $parts): string
    {
        $path = self::removeDotSegments((string) ($parts['path'] ?? '/'));
        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private static function removeDotSegments(string $path): string
    {
        $trailingSlash = str_ends_with($path, '/');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        $normalized = '/' . implode('/', $segments);
        return $trailingSlash && $normalized !== '/' ? $normalized . '/' : $normalized;
    }
}
