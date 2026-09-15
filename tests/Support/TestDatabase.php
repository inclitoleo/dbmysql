<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Support;

use Inclitoleo\Mysql\Connection\ConnectionConfig;
use Inclitoleo\Mysql\Connection\ConnectionManager;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Security\SchemaRegistry;
use PDO;

final class TestDatabase
{
    public static function config(): ?ConnectionConfig
    {
        $dsn = self::env('DBMYSQL_TEST_DSN');
        if ($dsn !== null && $dsn !== '') {
            return self::fromDsn($dsn);
        }

        $host = self::env('MYSQL_HOST');
        if ($host === null || $host === '') {
            return null;
        }

        return new ConnectionConfig(
            host: $host,
            port: (int) (self::env('MYSQL_PORT') ?? '3306'),
            database: self::env('MYSQL_DATABASE') ?? 'dbmysql_test',
            username: self::env('MYSQL_USER') ?? 'root',
            password: self::env('MYSQL_PASSWORD') ?? '',
        );
    }

    public static function manager(): ConnectionManager
    {
        $config = self::config();
        if ($config === null) {
            throw new \RuntimeException('Test database is not configured.');
        }

        return new ConnectionManager($config);
    }

    public static function schema(): SchemaRegistry
    {
        $schema = new SchemaRegistry();
        $schema->register('account', ['id', 'name', 'email']);
        $schema->register('orders', ['id', 'account_id', 'total']);
        $schema->register('logs', ['id', 'message', 'created_at']);

        return $schema;
    }

    public static function builder(?ConnectionManager $manager = null, ?SchemaRegistry $schema = null): QueryBuilder
    {
        return new QueryBuilder($schema ?? self::schema(), $manager ?? self::manager());
    }

    public static function ensureSchema(PDO $pdo): void
    {
        $sql = file_get_contents(dirname(__DIR__) . '/fixtures/init.sql');
        if ($sql === false) {
            throw new \RuntimeException('Unable to read init.sql');
        }
        $pdo->exec($sql);
    }

    public static function reset(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('TRUNCATE TABLE orders');
        $pdo->exec('TRUNCATE TABLE logs');
        $pdo->exec('TRUNCATE TABLE account');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $pdo->exec("INSERT INTO account (id, name, email) VALUES (1, 'Seed User', 'seed@example.com')");
        $pdo->exec("INSERT INTO orders (account_id, total) VALUES (1, 10.50)");
        $pdo->exec("INSERT INTO logs (message) VALUES ('seed log')");
    }

    private static function fromDsn(string $dsn): ConnectionConfig
    {
        $parts = [];
        foreach (explode(';', (string) preg_replace('/^mysql:/i', '', $dsn)) as $piece) {
            if (!str_contains($piece, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $piece, 2);
            $parts[strtolower(trim($key))] = trim($value);
        }

        return new ConnectionConfig(
            host: $parts['host'] ?? '127.0.0.1',
            port: (int) ($parts['port'] ?? '3306'),
            database: $parts['dbname'] ?? $parts['database'] ?? 'dbmysql_test',
            username: self::env('DBMYSQL_TEST_USER') ?? self::env('MYSQL_USER') ?? 'root',
            password: self::env('DBMYSQL_TEST_PASSWORD') ?? self::env('MYSQL_PASSWORD') ?? '',
        );
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }
}
