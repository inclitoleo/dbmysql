<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Unit\Connection;

use Inclitoleo\Mysql\Connection\ConnectionConfig;
use Inclitoleo\Mysql\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;

final class FrameworkConfigTest extends TestCase
{
    public function testFromLaravelConnectionArray(): void
    {
        $config = ConnectionConfig::fromArray([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'laravel',
            'username' => 'forge',
            'password' => 'secret',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $this->assertSame('127.0.0.1', $config->host);
        $this->assertSame(3306, $config->port);
        $this->assertSame('laravel', $config->database);
        $this->assertSame('forge', $config->username);
        $this->assertSame('secret', $config->password);
        $this->assertSame('utf8mb4', $config->charset);
        $this->assertFalse($config->debug);
    }

    public function testFromLaravelReadWriteHostArray(): void
    {
        $config = ConnectionConfig::fromArray([
            'host' => ['write' => 'write.internal', 'read' => 'read.internal'],
            'database' => 'app',
            'username' => 'app',
            'password' => 'p',
        ]);

        $this->assertSame('write.internal', $config->host);
    }

    public function testFromCakephpDatasourceArray(): void
    {
        $config = ConnectionConfig::fromArray([
            'className' => 'Cake\\Database\\Connection',
            'driver' => 'Cake\\Database\\Driver\\Mysql',
            'host' => 'localhost',
            'port' => 3307,
            'username' => 'my_app',
            'password' => 'secret',
            'database' => 'my_app',
            'encoding' => 'utf8mb4',
            'persistent' => false,
        ]);

        $this->assertSame('localhost', $config->host);
        $this->assertSame(3307, $config->port);
        $this->assertSame('my_app', $config->database);
        $this->assertSame('my_app', $config->username);
        $this->assertSame('utf8mb4', $config->charset);
    }

    public function testFromSymfonyDoctrineArray(): void
    {
        $config = ConnectionConfig::fromArray([
            'host' => '127.0.0.1',
            'port' => 3306,
            'dbname' => 'app',
            'user' => 'app',
            'password' => 'secret',
            'charset' => 'utf8mb4',
        ]);

        $this->assertSame('app', $config->database);
        $this->assertSame('app', $config->username);
    }

    public function testFromSymfonyDatabaseUrl(): void
    {
        $config = ConnectionConfig::fromDsn(
            'mysql://db_user:p%40ss@127.0.0.1:3306/db_name?serverVersion=8.4.0&charset=utf8mb4',
        );

        $this->assertSame('127.0.0.1', $config->host);
        $this->assertSame(3306, $config->port);
        $this->assertSame('db_name', $config->database);
        $this->assertSame('db_user', $config->username);
        $this->assertSame('p@ss', $config->password);
        $this->assertSame('utf8mb4', $config->charset);
    }

    public function testFromMariadbUrl(): void
    {
        $config = ConnectionConfig::fromDsn('mariadb://root@localhost/app');

        $this->assertSame('localhost', $config->host);
        $this->assertSame(3306, $config->port);
        $this->assertSame('app', $config->database);
        $this->assertSame('root', $config->username);
        $this->assertSame('', $config->password);
    }

    public function testFromPdoDsnWithCredentials(): void
    {
        $config = ConnectionConfig::fromDsn(
            'mysql:host=db.internal;port=3307;dbname=app;charset=utf8mb4',
            'u',
            'p',
            true,
        );

        $this->assertSame('db.internal', $config->host);
        $this->assertSame(3307, $config->port);
        $this->assertSame('app', $config->database);
        $this->assertSame('u', $config->username);
        $this->assertSame('p', $config->password);
        $this->assertTrue($config->debug);
    }

    public function testLaravelDatabaseUrlWinsOverHostKeys(): void
    {
        $config = ConnectionConfig::fromArray([
            'url' => 'mysql://url_user:url_pass@db.example:3308/from_url',
            'host' => 'ignored',
            'database' => 'ignored',
            'username' => 'ignored',
            'password' => 'ignored',
        ]);

        $this->assertSame('db.example', $config->host);
        $this->assertSame(3308, $config->port);
        $this->assertSame('from_url', $config->database);
        $this->assertSame('url_user', $config->username);
        $this->assertSame('url_pass', $config->password);
    }

    public function testEmptyDsnThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        ConnectionConfig::fromDsn('   ');
    }

    public function testUrlWithoutHostThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        ConnectionConfig::fromDsn('mysql:///dbname');
    }
}
