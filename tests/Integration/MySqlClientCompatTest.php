<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Integration;

use Inclitoleo\Mysql\database\MySqlClient;
use Inclitoleo\Mysql\Exception\MysqlException;
use Inclitoleo\Mysql\Tests\Support\TestDatabase;

final class MySqlClientCompatTest extends IntegrationTestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testCompatModeReturnsFalseAndEchoes(): void
    {
        $this->defineLegacyConstants(false);
        $client = new MySqlClient();
        ob_start();
        $result = $client->select('account; DROP TABLE account', 'id', 1);
        $output = ob_get_clean();
        $this->assertFalse($result);
        $this->assertNotSame('', $output);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testStrictModePropagatesException(): void
    {
        $this->defineLegacyConstants(true);
        $client = new MySqlClient();
        $this->expectException(MysqlException::class);
        $client->select('account; DROP TABLE account', 'id', 1);
    }

    private function defineLegacyConstants(bool $strict): void
    {
        $config = TestDatabase::config();
        $this->assertNotNull($config);
        define('INCLITOHOST', $config->host);
        define('INCLITOPORT', $config->port);
        define('INCLITODBNAME', $config->database);
        define('INCLITOUSER', $config->username);
        define('INCLITOPWD', $config->password);
        define('INCLITODRIVER', 'mysql');
        define('INCLITOTYPECONN', false);
        define('INCLITOBOOLCERT', false);
        define('INCLITOMYCA', '');
        define('INCLITOMYCERT', '');
        define('INCLITOMYKEY', '');
        define('INCLITOSTRICT', $strict);
    }
}
