<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Connection;

use PDO;

final class PersistentConnectionStrategy implements ConnectionStrategy
{
    public function connect(ConnectionConfig $config): PDO
    {
        return PdoFactory::connect($config, true);
    }

    public function disconnect(PDO $pdo): void
    {
        unset($pdo);
    }
}
