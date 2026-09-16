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

    /**
     * Build config from Laravel `database.connections.*`, CakePHP `Datasources.*`,
     * or Symfony Doctrine connection arrays.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if (isset($config['url']) && is_string($config['url']) && $config['url'] !== '') {
            return self::fromDsn(
                $config['url'],
                (string) ($config['username'] ?? $config['user'] ?? $config['login'] ?? ''),
                (string) ($config['password'] ?? $config['pass'] ?? ''),
                (bool) ($config['debug'] ?? false),
            );
        }

        $host = $config['host'] ?? '';
        if (is_array($host)) {
            $host = $host['write'] ?? $host[0] ?? '';
        }
        $host = (string) $host;

        $port = $config['port'] ?? null;
        if (($port === null || $port === '' || (int) $port === 0) && str_contains($host, ':')) {
            [$host, $embedded] = explode(':', $host, 2);
            $port = $embedded;
        }

        $charset = (string) ($config['charset'] ?? $config['encoding'] ?? 'utf8mb4');
        if (str_contains($charset, '_')) {
            $charset = explode('_', $charset, 2)[0];
        }

        $sslCa = (string) ($config['ssl_ca'] ?? $config['sslCa'] ?? '');
        $sslCert = (string) ($config['ssl_cert'] ?? $config['sslCert'] ?? '');
        $sslKey = (string) ($config['ssl_key'] ?? $config['sslKey'] ?? '');
        $sslEnabled = (bool) ($config['sslEnabled'] ?? $config['ssl'] ?? ($sslCa !== '' || $sslCert !== ''));
        $ssl = $sslEnabled ? new SslConfig($sslCa, $sslCert, $sslKey) : null;

        return new self(
            host: $host,
            port: (int) ($port ?: 3306),
            database: (string) ($config['database'] ?? $config['dbname'] ?? ''),
            username: (string) ($config['username'] ?? $config['user'] ?? $config['login'] ?? ''),
            password: (string) ($config['password'] ?? $config['pass'] ?? ''),
            charset: $charset,
            persistent: (bool) ($config['persistent'] ?? $config['persistentConnections'] ?? false),
            sslEnabled: $sslEnabled,
            ssl: $ssl,
            debug: (bool) ($config['debug'] ?? false),
        );
    }

    /**
     * Accepts Symfony `DATABASE_URL` (`mysql://user:pass@host:3306/db?charset=utf8mb4`)
     * or a PDO DSN (`mysql:host=127.0.0.1;port=3306;dbname=app`).
     */
    public static function fromDsn(
        string $dsn,
        string $username = '',
        string $password = '',
        bool $debug = false,
    ): self {
        $dsn = trim($dsn);
        if ($dsn === '') {
            throw new ConfigurationException('Connection DSN must not be empty.', 0, null, $debug);
        }

        if (preg_match('/^(mysql2?|mariadb|pdo_mysql):\\/\\//i', $dsn) === 1) {
            return self::fromUrl($dsn, $debug, $username, $password);
        }

        $parts = [];
        $withoutScheme = (string) preg_replace('/^mysql:/i', '', $dsn);
        foreach (explode(';', $withoutScheme) as $piece) {
            if (!str_contains($piece, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $piece, 2);
            $parts[strtolower(trim($key))] = trim($value);
        }

        return self::fromArray([
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? 3306,
            'database' => $parts['dbname'] ?? $parts['database'] ?? '',
            'username' => $username,
            'password' => $password,
            'charset' => $parts['charset'] ?? 'utf8mb4',
            'debug' => $debug,
        ]);
    }

    private static function fromUrl(string $url, bool $debug, string $username = '', string $password = ''): self
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            throw new ConfigurationException('Connection DSN must include a host.', 0, null, $debug);
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $database = ltrim((string) ($parts['path'] ?? ''), '/');
        if (str_contains($database, '/')) {
            $database = explode('/', $database, 2)[0];
        }

        return self::fromArray([
            'host' => $parts['host'],
            'port' => $parts['port'] ?? 3306,
            'database' => $database,
            'username' => isset($parts['user']) ? rawurldecode($parts['user']) : $username,
            'password' => array_key_exists('pass', $parts) ? rawurldecode((string) $parts['pass']) : $password,
            'charset' => (string) ($query['charset'] ?? 'utf8mb4'),
            'debug' => $debug,
        ]);
    }
}
