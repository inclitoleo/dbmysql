<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Regression;

use Inclitoleo\Mysql\database\MySqlClient;
use Inclitoleo\Mysql\Tests\Integration\IntegrationTestCase;
use Inclitoleo\Mysql\Tests\Support\TestDatabase;

final class MySqlClientTest extends IntegrationTestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInsertSelectUpdateDeleteAndExecute(): void
    {
        $this->defineLegacyConstants();
        $db = new MySqlClient();

        $insert = new \stdClass();
        $insert->name = 'Leonardo Costa';
        $insert->email = 'inclitoleo@example.com';
        $lastId = $db->insert('account', $insert);
        $this->assertIsInt($lastId);
        $this->assertGreaterThan(0, $lastId);

        $row = $db->select('account', 'id', $lastId);
        $this->assertIsObject($row);
        $this->assertSame('Leonardo Costa', $row->name);
        $this->assertSame('inclitoleo@example.com', $row->email);

        $all = $db->select_s('account');
        $this->assertIsArray($all);
        $this->assertNotEmpty($all);

        $sorted = $db->select_s('account', ['name', 'ASC']);
        $this->assertIsArray($sorted);

        $one = $db->select_all('SELECT * FROM account WHERE id = ' . (int) $lastId);
        $this->assertIsObject($one);
        $this->assertSame('Leonardo Costa', $one->name);

        $many = $db->select_all('SELECT * FROM account', 'A');
        $this->assertIsArray($many);

        $update = new \stdClass();
        $update->name = 'Kazan Name';
        $update->email = 'linuxmanbr@example.com';
        $this->assertTrue($db->update('account', $update, 'id', (string) $lastId));

        $updated = $db->select('account', 'id', $lastId);
        $this->assertSame('Kazan Name', $updated->name);

        $selected = $db->execute('SELECT * FROM account');
        $this->assertIsArray($selected);
        $this->assertNotEmpty($selected);

        $written = $db->execute("INSERT INTO account (name,email) VALUES ('New Name','newmail@example.com')");
        $this->assertIsArray($written);

        $this->assertTrue($db->delete('account', 'id', $lastId));
        $this->assertFalse($db->select('account', 'id', $lastId));
    }

    private function defineLegacyConstants(): void
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
        define('INCLITOSTRICT', false);
    }
}
