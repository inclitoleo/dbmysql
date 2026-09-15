<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Unit\Exception;

use Inclitoleo\Mysql\Connection\ConnectionConfig;
use Inclitoleo\Mysql\Connection\ConnectionManager;
use Inclitoleo\Mysql\Exception\ErrorCode;
use Inclitoleo\Mysql\Exception\InvalidIdentifierException;
use Inclitoleo\Mysql\Exception\NotFoundException;
use Inclitoleo\Mysql\Exception\QueryException;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Query\QueryExecutor;
use Inclitoleo\Mysql\Security\SchemaRegistry;
use Inclitoleo\Mysql\Tests\Support\FakeConnectionStrategy;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class PublicErrorResponseTest extends TestCase
{
    public function testDebugFalseReturnsJsonCodeOnlyForDuplicateKey(): void
    {
        $pdoException = new PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'leo.inclito@gmail.com' for key 'account.uq_account_email'",
            23000,
        );
        $pdoException->errorInfo = ['23000', 1062, 'Duplicate entry'];

        $e = $this->queryExceptionFromPdo($pdoException, debug: false);

        $this->assertFalse($e->isDebug());
        $this->assertSame(ErrorCode::FAIL, $e->publicCode());
        $this->assertSame('{"code":500}', $e->getMessage());
        $this->assertSame('{"code":500}', $e->toJson());
        $this->assertStringNotContainsString('leo.inclito@gmail.com', $e->getMessage());
        $this->assertStringContainsString('Duplicate entry', $e->getDetail());
    }

    public function testDebugTrueExposesRawSqlStateMessage(): void
    {
        $raw = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'leo.inclito@gmail.com' for key 'account.uq_account_email'";
        $pdoException = new PDOException($raw, 23000);
        $pdoException->errorInfo = ['23000', 1062, 'Duplicate entry'];

        $e = $this->queryExceptionFromPdo($pdoException, debug: true);

        $this->assertTrue($e->isDebug());
        $this->assertSame(ErrorCode::FAIL, $e->publicCode());
        $this->assertSame('Query failed: ' . $raw, $e->getMessage());
        $this->assertSame('{"code":500}', $e->toJson());
    }

    public function testAccessDeniedMapsTo403(): void
    {
        $e = new \Inclitoleo\Mysql\Exception\ConnectionException('Failed to connect', '28000', 1045, null, false);

        $this->assertSame(ErrorCode::FORBIDDEN, $e->publicCode());
        $this->assertSame('{"code":403}', $e->getMessage());
    }

    public function testInvalidIdentifierMapsTo403(): void
    {
        $e = new InvalidIdentifierException('Invalid SQL identifier: account; DROP', 'account; DROP', 'semicolon');

        $this->assertSame(ErrorCode::FORBIDDEN, $e->publicCode());
        $this->assertSame('{"code":403}', $e->getMessage());
        $this->assertSame('account; DROP', $e->getIdentifier());
    }

    public function testUnknownTableMapsTo404(): void
    {
        $pdoException = new PDOException('SQLSTATE[42S02]: Base table or view not found: 1146', 42);
        $pdoException->errorInfo = ['42S02', 1146, 'not found'];

        $e = $this->queryExceptionFromPdo($pdoException, debug: false);

        $this->assertSame(ErrorCode::NOT_FOUND, $e->publicCode());
        $this->assertSame('{"code":404}', $e->getMessage());
    }

    public function testFirstOrFailThrows404Json(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([]);

        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
        $pdo->method('prepare')->willReturn($statement);

        $schema = new SchemaRegistry();
        $schema->register('account', ['id']);
        $manager = new ConnectionManager(new ConnectionConfig('localhost'), new FakeConnectionStrategy($pdo));
        $builder = new QueryBuilder($schema, $manager);

        try {
            $builder->from('account')->where('id', '=', 999)->firstOrFail();
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame(404, $e->publicCode());
            $this->assertSame('{"code":404}', $e->getMessage());
        }
    }

    public function testOkJson(): void
    {
        $this->assertSame('{"code":200}', ErrorCode::ok());
    }

    private function queryExceptionFromPdo(PDOException $pdoException, bool $debug): QueryException
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willThrowException($pdoException);

        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
        $pdo->method('prepare')->willReturn($statement);

        $manager = new ConnectionManager(
            new ConnectionConfig('localhost', debug: $debug),
            new FakeConnectionStrategy($pdo),
        );
        $executor = new QueryExecutor($manager);

        try {
            $executor->fetchAll('INSERT INTO account (email) VALUES (?)', ['leo.inclito@gmail.com']);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            return $e;
        }
    }
}
