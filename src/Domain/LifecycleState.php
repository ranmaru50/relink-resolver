<?php
// src/Domain/LifecycleState.php
// Resolver レコードのライフサイクル状態。

declare(strict_types=1);

namespace Relink\Resolver\Domain;

use InvalidArgumentException;

enum LifecycleState: string
{
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case RETIRED = 'RETIRED';

    public static function fromInput(string $value): self
    {
        $value = strtoupper(trim($value));
        return self::tryFrom($value) ?? throw new InvalidArgumentException('Invalid lifecycle state');
    }

    public function manifestValue(): string
    {
        return strtolower($this->value);
    }
}
