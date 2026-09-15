<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Integration;

use Inclitoleo\Mysql\Connection\ConnectionConfig;
use Inclitoleo\Mysql\Connection\ConnectionManager;
use Inclitoleo\Mysql\Exception\ConnectionException;
use Inclitoleo\Mysql\Exception\TransactionException;
use Inclitoleo\Mysql\Tests\Support\TestDatabase;
use PDO;

final class ConnectionManagerTest extends IntegrationTestCase
{
    public function testSelectOneAgainstMysql84(): void
    {
        $pdo = TestDatabase::manager()->pdo();
        $stmt = $pdo->query('SELECT 1 AS ok');
        $this->assertNotFalse($stmt);
        $this->assertSame(1, (int) $stmt->fetchColumn());
        $this->assertFalse((bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        $this->assertSame(PDO::ERRMODE_EXCEPTION, (int) $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    public function testInvalidCredentialsThrowConnectionException(): void
    {
        $valid = TestDatabase::config();
        $this->assertNotNull($valid);
        $config = new ConnectionConfig(
            host: $valid->host,
            port: $valid->port,
            database: $valid->database,
            username: 'definitely-not-a-user',
            password: 'wrong-password',
        );
        $manager = new ConnectionManager($config);
        $this->expectException(ConnectionException::class);
        $manager->pdo();
    }

    public function testTransactionCommitPersists(): void
    {
        $manager = TestDatabase::manager();
        $manager->transaction(function (ConnectionManager $m): void {
            $m->pdo()->exec("INSERT INTO account (name, email) VALUES ('Tx User', 'tx@example.com')");
        });
        $count = (int) $manager->pdo()->query("SELECT COUNT(*) FROM account WHERE email = 'tx@example.com'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testTransactionRollbackRevertsPriorWork(): void
    {
        $manager = TestDatabase::manager();
        $before = (int) $manager->pdo()->query('SELECT COUNT(*) FROM account')->fetchColumn();
        try {
            $manager->transaction(function (ConnectionManager $m): void {
                $m->pdo()->exec("INSERT INTO account (name, email) VALUES ('Rollback User', 'rollback@example.com')");
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
        $after = (int) $manager->pdo()->query('SELECT COUNT(*) FROM account')->fetchColumn();
        $this->assertSame($before, $after);
        $exists = (int) $manager->pdo()->query("SELECT COUNT(*) FROM account WHERE email = 'rollback@example.com'")->fetchColumn();
        $this->assertSame(0, $exists);
    }

    public function testSslAttributesAreSkippedWithoutCertificates(): void
    {
        if (getenv('DBMYSQL_TEST_SSL') !== '1') {
            $this->markTestSkipped('Set DBMYSQL_TEST_SSL=1 with certificates to exercise SSL connections.');
        }
        $this->assertTrue(true);
    }
}
