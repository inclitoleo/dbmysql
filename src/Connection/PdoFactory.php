<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Connection;

use Inclitoleo\Mysql\Exception\ConnectionException;
use PDO;
use PDOException;

final class PdoFactory
{
    public static function dsn(ConnectionConfig $config): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config->host,
            $config->port,
            $config->database,
            $config->charset,
        );
    }

    /**
     * @return array<int, mixed>
     */
    public static function options(ConnectionConfig $config, bool $persistent): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . $config->charset,
            PDO::ATTR_PERSISTENT => $persistent,
            PDO::ATTR_TIMEOUT => 2,
        ];

        if (defined('PDO::MYSQL_ATTR_INT_AND_FLOAT_NATIVE')) {
            $options[PDO::MYSQL_ATTR_INT_AND_FLOAT_NATIVE] = true;
        }

        if ($config->sslEnabled && $config->ssl !== null) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $config->ssl->ca;
            $options[PDO::MYSQL_ATTR_SSL_CERT] = $config->ssl->cert;
            $options[PDO::MYSQL_ATTR_SSL_KEY] = $config->ssl->key;
        }

        return $options;
    }

    public static function connect(ConnectionConfig $config, bool $persistent): PDO
    {
        try {
            $pdo = new PDO(
                self::dsn($config),
                $config->username,
                $config->password,
                self::options($config, $persistent),
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            return $pdo;
        } catch (PDOException $e) {
            throw new ConnectionException(
                'Failed to connect to MySQL: ' . $e->getMessage(),
                self::sqlStateFrom($e),
                (int) $e->getCode(),
                $e,
                $config->debug,
            );
        }
    }

    public static function sqlStateFrom(PDOException $e): ?string
    {
        if (isset($e->errorInfo[0]) && is_string($e->errorInfo[0]) && $e->errorInfo[0] !== '') {
            return $e->errorInfo[0];
        }

        if (preg_match('/SQLSTATE\[([A-Z0-9]+)\]/i', $e->getMessage(), $matches) === 1) {
            return $matches[1];
        }

        $code = (string) $e->getCode();

        return $code !== '' && $code !== '0' ? $code : null;
    }
}
