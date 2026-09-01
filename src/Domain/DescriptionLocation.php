<?php
// src/Domain/DescriptionLocation.php
// 公開リダイレクト先となる HTTPS 絶対 URI の値オブジェクト。

declare(strict_types=1);

namespace Relink\Resolver\Domain;

use InvalidArgumentException;

final readonly class DescriptionLocation
{
    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);
        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($value === '' || filter_var($value, FILTER_VALIDATE_URL) === false || $scheme !== 'https' || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new InvalidArgumentException('Description Location must be an absolute HTTPS URL');
        }
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
