<?php
// src/Domain/IntegrityMetadata.php
// Manifest の任意 integrity metadata を表す検証済み値オブジェクト。

declare(strict_types=1);

namespace Relink\Resolver\Domain;

use InvalidArgumentException;

final readonly class IntegrityMetadata
{
    public function __construct(public string $algorithm, public string $digest)
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/D', $this->algorithm)) {
            throw new InvalidArgumentException('Invalid integrity algorithm');
        }
        if (!preg_match('/^[0-9a-f]+$/D', $this->digest) || strlen($this->digest) < 2) {
            throw new InvalidArgumentException('Invalid integrity digest');
        }
        if ($this->algorithm === 'sha-256' && strlen($this->digest) !== 64) {
            throw new InvalidArgumentException('Invalid sha-256 digest');
        }
    }
}
