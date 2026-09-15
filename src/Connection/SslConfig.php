<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Connection;

final class SslConfig
{
    public function __construct(
        public readonly string $ca = '',
        public readonly string $cert = '',
        public readonly string $key = '',
    ) {
    }
}
