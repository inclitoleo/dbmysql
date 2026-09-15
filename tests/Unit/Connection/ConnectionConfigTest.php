<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Unit\Connection;

use Inclitoleo\Mysql\Connection\ConnectionConfig;
use Inclitoleo\Mysql\Connection\ConnectionManager;
use Inclitoleo\Mysql\Connection\PdoFactory;
use Inclitoleo\Mysql\Connection\PersistentConnectionStrategy;
use Inclitoleo\Mysql\Connection\SingleConnectionStrategy;
use Inclitoleo\Mysql\Connection\SslConfig;
use Inclitoleo\Mysql\Exception\ConfigurationException;
use Inclitoleo\Mysql\Exception\ConnectionException;
use Inclitoleo\Mysql\Exception\TransactionException;
use Inclitoleo\Mysql\Tests\Support\FakeConnectionStrategy;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ConnectionConfigTest extends TestCase
{
    public function testValidConfigIsAccepted(): void
    {
        $config = new ConnectionConfig('localhost', 3306, 'dbmysql_test', 'user', 'secret');

        $this->assertSame('localhost', $config->host);
        $this->assertSame(3306, $config->port);
        $this->assertSame('utf8mb4', $config->charset);
        $this->assertFalse($config->sslEnabled);
        $this->assertFalse($config->persistent);
    }

    public function testEmptyHostThrowsBeforePdo(): void
    {
        $this->expectException(ConfigurationException::class);
        new ConnectionConfig('');
    }

    /**
     * @dataProvider invalidPorts
     */
    public function testInvalidPortThrowsBeforePdo(int $port): void
    {
        $this->expectException(ConfigurationException::class);
        new ConnectionConfig('localhost', $port);
    }

    /**
     * @return list<list<int>>
     */
    public static function invalidPorts(): array
    {
        return [[0], [-1], [65536]];
    }

    public function testInvalidCharsetIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new ConnectionConfig('localhost', 3306, charset: 'utf8mb4; DROP');
    }

    public function testSslEnabledWithoutConfigThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        new ConnectionConfig('localhost', sslEnabled: true);
    }

    public function testBoundaryPortsAreValid(): void
    {
        $this->assertSame(1, (new ConnectionConfig('h', 1))->port);
        $this->assertSame(65535, (new ConnectionConfig('h', 65535))->port);
    }

    public function testPdoFactoryBuildsDsnAndSafeOptions(): void
    {
        $config = new ConnectionConfig('db.example', 3307, 'app', 'u', 'p');
        $options = PdoFactory::options($config, false);

        $this->assertSame('mysql:host=db.example;port=3307;dbname=app;charset=utf8mb4', PdoFactory::dsn($config));
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
        $this->assertFalse($options[PDO::ATTR_EMULATE_PREPARES]);
        $this->assertSame('SET NAMES utf8mb4', $options[PDO::MYSQL_ATTR_INIT_COMMAND]);
        $this->assertFalse($options[PDO::ATTR_PERSISTENT]);
        $this->assertSame(2, $options[PDO::ATTR_TIMEOUT]);
        $this->assertArrayNotHasKey(PDO::MYSQL_ATTR_SSL_CA, $options);
    }

    public function testPdoFactoryAddsSslAndPersistentOptions(): void
    {
        $config = new ConnectionConfig(
            'db.example',
            sslEnabled: true,
            ssl: new SslConfig('/ca.pem', '/cert.pem', '/key.pem'),
        );
        $options = PdoFactory::options($config, true);

        $this->assertTrue($options[PDO::ATTR_PERSISTENT]);
        $this->assertSame('/ca.pem', $options[PDO::MYSQL_ATTR_SSL_CA]);
        $this->assertSame('/cert.pem', $options[PDO::MYSQL_ATTR_SSL_CERT]);
        $this->assertSame('/key.pem', $options[PDO::MYSQL_ATTR_SSL_KEY]);
    }

    public function testDefaultStrategyIsSingleConnection(): void
    {
        $manager = new ConnectionManager(new ConnectionConfig('localhost'));
        $this->assertInstanceOf(SingleConnectionStrategy::class, $manager->strategy());
    }

    public function testPersistentConfigSelectsPersistentStrategy(): void
    {
        $manager = new ConnectionManager(new ConnectionConfig('localhost', persistent: true));
        $this->assertInstanceOf(PersistentConnectionStrategy::class, $manager->strategy());
    }

    public function testReadUsesReplicaAndWriteUsesPrimary(): void
    {
        $primary = $this->mockPdo();
        $replica = $this->mockPdo();
        $manager = new ConnectionManager(
            new ConnectionConfig('primary-host'),
            new FakeConnectionStrategy($primary),
        );
        $manager->registerEndpoint('replica', new ConnectionConfig('replica-host'));

        $ref = new \ReflectionProperty(ConnectionManager::class, 'connections');
        $ref->setAccessible(true);
        $ref->setValue($manager, [
            'primary' => $primary,
            'replica' => $replica,
        ]);

        $this->assertSame($replica, $manager->connectionFor('read'));
        $this->assertSame($primary, $manager->connectionFor('write'));
        $this->assertSame($primary, $manager->pdo());
        $this->assertSame($primary, $manager->connection());
    }

    public function testReadFallsBackToPrimaryWhenReplicaMissing(): void
    {
        $pdo = $this->mockPdo();
        $manager = new ConnectionManager(new ConnectionConfig('localhost'), new FakeConnectionStrategy($pdo));

        $this->assertSame($pdo, $manager->connectionFor('read'));
    }

    public function testInvalidEndpointRoleThrows(): void
    {
        $manager = new ConnectionManager(new ConnectionConfig('localhost'), new FakeConnectionStrategy($this->mockPdo()));
        $this->expectException(ConfigurationException::class);
        $manager->registerEndpoint('analytics', new ConnectionConfig('other'));
    }

    public function testInvalidOperationThrows(): void
    {
        $manager = new ConnectionManager(new ConnectionConfig('localhost'), new FakeConnectionStrategy($this->mockPdo()));
        $this->expectException(ConfigurationException::class);
        $manager->connectionFor('admin');
    }

    public function testTransactionCommitsOnSuccess(): void
    {
        $pdo = $this->mockPdo();
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('commit')->willReturn(true);
        $pdo->expects($this->never())->method('rollBack');

        $manager = new ConnectionManager(new ConnectionConfig('localhost'), new FakeConnectionStrategy($pdo));
        $result = $manager->transaction(static fn (ConnectionManager $m): string => 'ok');

        $this->assertSame('ok', $result);
    }

    public function testTransactionRollsBackAndWrapsFailure(): void
    {
        $pdo = $this->mockPdo();
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willReturn(true);
        $pdo->expects($this->never())->method('commit');

        $deadlock = new PDOException('Deadlock', 40001);
        $deadlock->errorInfo = ['40001', 1213, 'Deadlock found'];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $manager = new ConnectionManager(
            new ConnectionConfig('localhost'),
            new FakeConnectionStrategy($pdo),
            $logger,
        );

        try {
            $manager->transaction(static function () use ($deadlock): void {
                throw $deadlock;
            });
            $this->fail('Expected TransactionException');
        } catch (TransactionException $e) {
            $this->assertSame('40001', $e->getSqlState());
            $this->assertSame($deadlock, $e->getPrevious());
        }
    }

    public function testSqlStateFromPdoExceptionMessage(): void
    {
        $e = new PDOException('SQLSTATE[HY000] [2002] Connection refused');
        $this->assertSame('HY000', PdoFactory::sqlStateFrom($e));
    }

    public function testConnectFailureBecomesConnectionException(): void
    {
        $this->expectException(ConnectionException::class);
        PdoFactory::connect(new ConnectionConfig('127.0.0.1', 1, 'none', 'none', 'none'), false);
    }

    public function testConnectionFailureIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $strategy = $this->createMock(\Inclitoleo\Mysql\Connection\ConnectionStrategy::class);
        $strategy->method('connect')->willThrowException(new ConnectionException('denied', '28000'));

        $manager = new ConnectionManager(new ConnectionConfig('localhost'), $strategy, $logger);
        $this->expectException(ConnectionException::class);
        $manager->pdo();
    }

    public function testStrategiesDisconnectSafely(): void
    {
        $pdo = $this->mockPdo();
        (new SingleConnectionStrategy())->disconnect($pdo);
        (new PersistentConnectionStrategy())->disconnect($pdo);
        $this->addToAssertionCount(1);
    }

    public function testDisconnectClearsConnections(): void
    {
        $pdo = $this->mockPdo();
        $manager = new ConnectionManager(new ConnectionConfig('localhost'), new FakeConnectionStrategy($pdo));
        $manager->pdo();
        $manager->disconnect();

        $ref = new \ReflectionProperty(ConnectionManager::class, 'connections');
        $ref->setAccessible(true);
        $this->assertSame([], $ref->getValue($manager));
    }

    private function mockPdo(): PDO
    {
        return $this->getMockBuilder(PDO::class)
            ->disableOriginalConstructor()
            ->getMock();
    }
}
