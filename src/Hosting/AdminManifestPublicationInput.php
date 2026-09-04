<?php
// src/Hosting/AdminManifestPublicationInput.php
// 管理画面から受け取るManifest公開設定を、公開モードに応じて正規化する。

declare(strict_types=1);

namespace Relink\Resolver\Hosting;

final readonly class AdminManifestPublicationInput
{
    public function __construct(
        public ?string $algorithm,
        public ?string $digest,
    ) {
    }

    /**
     * POST値から整合性情報を作成する。
     *
     * supplied以外の公開モードでは、画面に残った古い値を業務サービスへ渡さない。
     *
     * @param array<string, mixed> $input
     */
    public static function fromPost(array $input): self
    {
        $mode = strtolower(trim((string) ($input['publication_mode'] ?? '')));
        if ($mode !== 'supplied') {
            return new self(null, null);
        }

        return new self(
            self::nullableString($input['integrity_algorithm'] ?? null),
            self::nullableString($input['integrity_digest'] ?? null),
        );
    }

    /** POST値の空文字を未指定として扱う。 */
    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '' || !is_scalar($value)) {
            return null;
        }

        return (string) $value;
    }
}
