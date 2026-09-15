<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Unit\Connection;

use Inclitoleo\Mysql\Connection\ConnectionConfig;
use Inclitoleo\Mysql\database\DataBaseConnection;
use PHPUnit\Framework\TestCase;

final class LegacyConfigTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testParsesHostWithEmbeddedPortWhenInclitoPortUndefined(): void
    {
        define('INCLITOHOST', 'db.internal:3307');
        define('INCLITODBNAME', 'app');
        define('INCLITOUSER', 'u');
        define('INCLITOPWD', 'p');
        define('INCLITODRIVER', 'mysql');

        $config = (new class extends DataBaseConnection {
            public function export(): ConnectionConfig
            {
                return $this->connectionConfigFromConstants();
            }
        })->export();

        $this->assertSame('db.internal', $config->host);
        $this->assertSame(3307, $config->port);
        $this->assertSame('app', $config->database);
        $this->assertSame('u', $config->username);
        $this->assertSame('p', $config->password);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInclitoPortOverridesEmbeddedHostPort(): void
    {
        define('INCLITOHOST', 'db.internal:3307');
        define('INCLITOPORT', 3306);
        define('INCLITODBNAME', 'app');
        define('INCLITOUSER', 'u');
        define('INCLITOPWD', 'p');
        define('INCLITODRIVER', 'mysql');

        $config = (new class extends DataBaseConnection {
            public function export(): ConnectionConfig
            {
                return $this->connectionConfigFromConstants();
            }
        })->export();

        $this->assertSame('db.internal', $config->host);
        $this->assertSame(3306, $config->port);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDefaultPortIs3306(): void
    {
        define('INCLITOHOST', 'localhost');
        define('INCLITODBNAME', 'app');
        define('INCLITOUSER', 'u');
        define('INCLITOPWD', 'p');

        $config = (new class extends DataBaseConnection {
            public function export(): ConnectionConfig
            {
                return $this->connectionConfigFromConstants();
            }
        })->export();

        $this->assertSame(3306, $config->port);
        $this->assertFalse($config->persistent);
        $this->assertFalse($config->sslEnabled);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSslConstantsMapToSslConfig(): void
    {
        define('INCLITOHOST', 'localhost');
        define('INCLITODBNAME', 'app');
        define('INCLITOUSER', 'u');
        define('INCLITOPWD', 'p');
        define('INCLITOBOOLCERT', true);
        define('INCLITOMYCA', '/ca');
        define('INCLITOMYCERT', '/cert');
        define('INCLITOMYKEY', '/key');
        define('INCLITOTYPECONN', true);

        $config = (new class extends DataBaseConnection {
            public function export(): ConnectionConfig
            {
                return $this->connectionConfigFromConstants();
            }
        })->export();

        $this->assertTrue($config->sslEnabled);
        $this->assertTrue($config->persistent);
        $this->assertSame('/ca', $config->ssl?->ca);
        $this->assertSame('/cert', $config->ssl?->cert);
        $this->assertSame('/key', $config->ssl?->key);
    }
}
