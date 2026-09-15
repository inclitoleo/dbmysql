<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Connection;

use PDO;

final class SingleConnectionStrategy implements ConnectionStrategy
{
    public function connect(ConnectionConfig $config): PDO
    {
        return PdoFactory::connect($config, false);
    }

    public function disconnect(PDO $pdo): void
    {
        unset($pdo);
    }
}
