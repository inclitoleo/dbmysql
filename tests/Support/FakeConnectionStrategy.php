<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Support;

use Inclitoleo\Mysql\Connection\ConnectionConfig;
use Inclitoleo\Mysql\Connection\ConnectionStrategy;
use PDO;

final class FakeConnectionStrategy implements ConnectionStrategy
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function connect(ConnectionConfig $config): PDO
    {
        return $this->pdo;
    }

    public function disconnect(PDO $pdo): void
    {
    }
}
