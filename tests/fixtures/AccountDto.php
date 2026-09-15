<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Fixtures;

final class AccountDto
{
    public function __construct(
        public readonly ?int $id = null,
        public readonly string $name = '',
        public readonly string $email = '',
        public readonly mixed $internalCache = null,
    ) {
    }
}
