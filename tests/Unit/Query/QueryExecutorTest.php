<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Unit\Query;

use Inclitoleo\Mysql\Connection\ConnectionConfig;
use Inclitoleo\Mysql\Connection\ConnectionManager;
use Inclitoleo\Mysql\Exception\QueryException;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Query\QueryExecutor;
use Inclitoleo\Mysql\Security\SchemaRegistry;
use Inclitoleo\Mysql\Tests\Support\FakeConnectionStrategy;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class QueryExecutorTest extends TestCase
{
    public function testQueryFailureLogsAndWrapsPdoException(): void
    {
        $pdoException = new PDOException('SQLSTATE[42000]: Syntax error', 42000);
        $pdoException->errorInfo = ['42000', 1064, 'You have an error'];

        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willThrowException($pdoException);

        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
        $pdo->method('prepare')->willReturn($statement);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'MySQL query failed',
            $this->callback(static function (array $context): bool {
                return ($context['sql'] ?? '') === 'SELECT nope' && ($context['sqlState'] ?? '') === '42000';
            }),
        );

        $manager = new ConnectionManager(
            new ConnectionConfig('localhost'),
            new FakeConnectionStrategy($pdo),
            $logger,
        );
        $executor = new QueryExecutor($manager, $logger);

        try {
            $executor->fetchAll('SELECT nope', [1]);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame('SELECT nope', $e->getSql());
            $this->assertSame([1], $e->getBindings());
            $this->assertSame('42000', $e->getSqlState());
        }
    }

    public function testFetchAllReturnsObjects(): void
    {
        $row = (object) ['id' => 1];
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([$row]);

        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
        $pdo->method('prepare')->willReturn($statement);

        $manager = new ConnectionManager(new ConnectionConfig('localhost'), new FakeConnectionStrategy($pdo));
        $executor = new QueryExecutor($manager);
        $this->assertSame([$row], $executor->fetchAll('SELECT * FROM account', []));
    }

    public function testExecuteAndLastInsertId(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('rowCount')->willReturn(1);

        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('lastInsertId')->willReturn('9');

        $manager = new ConnectionManager(new ConnectionConfig('localhost'), new FakeConnectionStrategy($pdo));
        $executor = new QueryExecutor($manager);
        $this->assertSame(1, $executor->execute('INSERT INTO account (name) VALUES (?)', ['x']));
        $this->assertSame('9', $executor->lastInsertId());
    }

    public function testBuilderRawUsesExecutor(): void
    {
        $row = (object) ['id' => 1];
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([$row]);

        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
        $pdo->method('prepare')->willReturn($statement);

        $schema = new SchemaRegistry();
        $schema->register('account', ['id']);
        $manager = new ConnectionManager(new ConnectionConfig('localhost'), new FakeConnectionStrategy($pdo));
        $builder = new QueryBuilder($schema, $manager);

        $this->assertSame([$row], $builder->raw('SELECT * FROM account WHERE id = ?', [1]));
    }

    public function testCursorYieldsRows(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturnOnConsecutiveCalls((object) ['id' => 1], false);
        $statement->expects($this->once())->method('closeCursor')->willReturn(true);

        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('setAttribute')->willReturn(true);

        $manager = new ConnectionManager(new ConnectionConfig('localhost'), new FakeConnectionStrategy($pdo));
        $executor = new QueryExecutor($manager);
        $rows = iterator_to_array($executor->cursor('SELECT * FROM account', []));
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]->id);
    }
}
