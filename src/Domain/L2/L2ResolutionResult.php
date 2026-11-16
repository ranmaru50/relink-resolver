<?php
// src/Domain/L2/L2ResolutionResult.php
// 公開 L2 応答の値オブジェクト。Status とヘッダに加え、§6.3 の transport framing
// {"records":[Record_0..Record_G]}（verbatim wire 文字列の JSON 配列）の本文を持つ。
// ResolutionResult（Stage 1 凍結範囲）は変更せず、加算的に追加する。

declare(strict_types=1);

namespace Relink\Resolver\Domain\L2;

final readonly class L2ResolutionResult
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public array $headers = [],
        public string $body = '',
    ) {
    }

    /**
     * §14.2: 400/404/500/501/503 SHOULD be no-store; 410 MAY be cached, and the reference
     * deployment applies its existing convention public, max-age=300 to 410.
     */
    public static function error(int $status): self
    {
        $cacheControl = $status === 410 ? 'public, max-age=300' : 'no-store';

        return new self($status, ['Cache-Control' => $cacheControl]);
    }
}
