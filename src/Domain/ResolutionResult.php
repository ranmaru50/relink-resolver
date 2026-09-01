<?php
// src/Domain/ResolutionResult.php
// HTTP アダプタへ渡す公開解決結果。

declare(strict_types=1);

namespace Relink\Resolver\Domain;

final readonly class ResolutionResult
{
    public function __construct(public int $status, public array $headers = [])
    {
    }

    public static function redirect(string $location, int $maxAge): self
    {
        return new self(303, [
            'Location' => $location,
            'Cache-Control' => 'public, max-age=' . max(0, $maxAge),
        ]);
    }

    public static function error(int $status): self
    {
        $cache = in_array($status, [400, 404, 500, 501, 503], true) ? 'no-store' : 'public, max-age=300';
        return new self($status, ['Cache-Control' => $cache]);
    }
}
