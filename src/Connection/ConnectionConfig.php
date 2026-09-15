<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Connection;

use Inclitoleo\Mysql\Exception\ConfigurationException;

final class ConnectionConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port = 3306,
        public readonly string $database = '',
        public readonly string $username = '',
        public readonly string $password = '',
        public readonly string $charset = 'utf8mb4',
        public readonly bool $persistent = false,
        public readonly bool $sslEnabled = false,
        public readonly ?SslConfig $ssl = null,
        public readonly bool $debug = false,
    ) {
        if ($this->host === '') {
            throw new ConfigurationException('Connection host must not be empty.', 0, null, $this->debug);
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new ConfigurationException('Connection port must be between 1 and 65535.', 0, null, $this->debug);
        }
        if (preg_match('/^[a-zA-Z0-9_]+$/', $this->charset) !== 1) {
            throw new ConfigurationException('Invalid connection charset.', 0, null, $this->debug);
        }
        if ($this->sslEnabled && $this->ssl === null) {
            throw new ConfigurationException('SSL is enabled but SslConfig is missing.', 0, null, $this->debug);
        }
    }
}
