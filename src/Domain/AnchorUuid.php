<?php
// src/Domain/AnchorUuid.php
// Anchor UUID の RFC 9562 形式と大小文字を扱う値オブジェクト。

declare(strict_types=1);

namespace Relink\Resolver\Domain;

use InvalidArgumentException;

final readonly class AnchorUuid
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = strtolower(trim($value));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $normalized)) {
            throw new InvalidArgumentException('Invalid UUID');
        }
        $this->value = $normalized;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
