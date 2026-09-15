<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Connection;

use PDO;

interface ConnectionStrategy
{
    public function connect(ConnectionConfig $config): PDO;

    public function disconnect(PDO $pdo): void;
}
