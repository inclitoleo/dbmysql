<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Unit\Exception;

use Inclitoleo\Mysql\Connection\ConnectionConfig;
use Inclitoleo\Mysql\Connection\ConnectionManager;
use Inclitoleo\Mysql\Exception\ConfigurationException;
use Inclitoleo\Mysql\Exception\ConnectionException;
use Inclitoleo\Mysql\Exception\ErrorCode;
use Inclitoleo\Mysql\Exception\InvalidIdentifierException;
use Inclitoleo\Mysql\Exception\MysqlException;
use Inclitoleo\Mysql\Exception\NotFoundException;
use Inclitoleo\Mysql\Exception\QueryException;
use Inclitoleo\Mysql\Exception\TransactionException;
use Inclitoleo\Mysql\Mapper\EntityMapper;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Query\QueryExecutor;
use Inclitoleo\Mysql\Security\SchemaRegistry;
use Inclitoleo\Mysql\Tests\Fixtures\AccountDto;
use Inclitoleo\Mysql\Tests\Fixtures\AccountRepository;
use Inclitoleo\Mysql\Tests\Support\FakeConnectionStrategy;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class PublicErrorResponseTest extends TestCase
{
    public function testDebugFalseReturnsJsonCodeOnlyForDuplicateKey(): void
    {
        $pdoException = $this->pdoException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'leo.inclito@gmail.com' for key 'account.uq_account_email'",
            '23000',
            1062,
        );

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
        $pdoException = $this->pdoException($raw, '23000', 1062);

        $e = $this->queryExceptionFromPdo($pdoException, debug: true);

        $this->assertTrue($e->isDebug());
        $this->assertSame(ErrorCode::FAIL, $e->publicCode());
        $this->assertSame('Query failed: ' . $raw, $e->getMessage());
        $this->assertSame('{"code":500}', $e->toJson());
    }

    public function testForeignKeyViolationMapsTo500(): void
    {
        $e = $this->queryExceptionFromPdo(
            $this->pdoException('SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row', '23000', 1452),
            debug: false,
        );

        $this->assertSame(ErrorCode::FAIL, $e->publicCode());
        $this->assertSame('{"code":500}', $e->getMessage());
        $this->assertStringNotContainsString('child row', $e->getMessage());
    }

    public function testSyntaxErrorMapsTo500(): void
    {
        $e = $this->queryExceptionFromPdo(
            $this->pdoException('SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax', '42000', 1064),
            debug: false,
        );

        $this->assertSame(ErrorCode::FAIL, $e->publicCode());
        $this->assertSame('{"code":500}', $e->getMessage());
    }

    public function testEmptyInsertMapsTo500Json(): void
    {
        $builder = new QueryBuilder($this->accountSchema(), new ConnectionManager(new ConnectionConfig('localhost')));

        try {
            $builder->insert('account', []);
            $this->fail('Expected QueryException');
        } catch (QueryException $e) {
            $this->assertSame(ErrorCode::FAIL, $e->publicCode());
            $this->assertSame('{"code":500}', $e->getMessage());
            $this->assertSame('insert requires at least one column', $e->getDetail());
        }
    }

    public function testConfigurationPortZeroMapsTo500(): void
    {
        try {
            new ConnectionConfig('localhost', port: 0);
            $this->fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            $this->assertSame(ErrorCode::FAIL, $e->publicCode());
            $this->assertSame('{"code":500}', $e->getMessage());
            $this->assertSame('Connection port must be between 1 and 65535.', $e->getDetail());
        }
    }

    public function testConfigurationDebugTrueExposesDetail(): void
    {
        try {
            new ConnectionConfig('localhost', port: 0, debug: true);
            $this->fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            $this->assertTrue($e->isDebug());
            $this->assertSame('Connection port must be between 1 and 65535.', $e->getMessage());
            $this->assertSame('{"code":500}', $e->toJson());
        }
    }

    public function testAccessDeniedMapsTo403(): void
    {
        $e = new ConnectionException('Failed to connect', '28000', 1045, null, false);

        $this->assertSame(ErrorCode::FORBIDDEN, $e->publicCode());
        $this->assertSame('{"code":403}', $e->getMessage());
        $this->assertSame('Failed to connect', $e->getDetail());
    }

    public function testAccessDeniedDebugTrueExposesRawMessage(): void
    {
        $raw = 'Failed to connect to MySQL: SQLSTATE[HY000] [1045] Access denied for user';
        $e = new ConnectionException($raw, '28000', 1045, null, true);

        $this->assertTrue($e->isDebug());
        $this->assertSame($raw, $e->getMessage());
        $this->assertSame('{"code":403}', $e->toJson());
    }

    public function testInvalidIdentifierMapsTo403(): void
    {
        $e = new InvalidIdentifierException('Invalid SQL identifier: account; DROP', 'account; DROP', 'semicolon');

        $this->assertSame(ErrorCode::FORBIDDEN, $e->publicCode());
        $this->assertSame('{"code":403}', $e->getMessage());
        $this->assertSame('account; DROP', $e->getIdentifier());
        $this->assertStringNotContainsString('DROP', $e->getMessage());
    }

    public function testUnregisteredTableMapsTo403(): void
    {
        $builder = new QueryBuilder($this->accountSchema());

        try {
            $builder->from('ghost');
            $this->fail('Expected InvalidIdentifierException');
        } catch (InvalidIdentifierException $e) {
            $this->assertSame(ErrorCode::FORBIDDEN, $e->publicCode());
            $this->assertSame('{"code":403}', $e->getMessage());
            $this->assertSame('ghost', $e->getIdentifier());
        }
    }

    public function testIdentifierInjectionMapsTo403(): void
    {
        $builder = new QueryBuilder($this->accountSchema());

        try {
            $builder->from('account; DROP TABLE account');
            $this->fail('Expected InvalidIdentifierException');
        } catch (InvalidIdentifierException $e) {
            $this->assertSame(ErrorCode::FORBIDDEN, $e->publicCode());
            $this->assertSame('{"code":403}', $e->getMessage());
            $this->assertStringNotContainsString('DROP TABLE', $e->getMessage());
        }
    }

    public function testInvalidOperatorMapsTo403(): void
    {
        $builder = new QueryBuilder($this->accountSchema());

        try {
            $builder->from('account')->where('id', 'OR', 1);
            $this->fail('Expected InvalidIdentifierException');
        } catch (InvalidIdentifierException $e) {
            $this->assertSame(ErrorCode::FORBIDDEN, $e->publicCode());
            $this->assertSame('{"code":403}', $e->getMessage());
            $this->assertSame('OR', $e->getIdentifier());
        }
    }

    public function testStackedRawSqlMapsTo403(): void
    {
        $builder = new QueryBuilder($this->accountSchema());

        try {
            $builder->raw('SELECT 1 FROM account; DROP TABLE account');
            $this->fail('Expected InvalidIdentifierException');
        } catch (InvalidIdentifierException $e) {
            $this->assertSame(ErrorCode::FORBIDDEN, $e->publicCode());
            $this->assertSame('{"code":403}', $e->getMessage());
        }
    }

    public function testUnknownTableMapsTo404(): void
    {
        $e = $this->queryExceptionFromPdo(
            $this->pdoException('SQLSTATE[42S02]: Base table or view not found: 1146 Table \'db.ghost\' doesn\'t exist', '42S02', 1146),
            debug: false,
        );

        $this->assertSame(ErrorCode::NOT_FOUND, $e->publicCode());
        $this->assertSame('{"code":404}', $e->getMessage());
        $this->assertStringNotContainsString('ghost', $e->getMessage());
    }

    public function testUnknownColumnMapsTo404(): void
    {
        $e = $this->queryExceptionFromPdo(
            $this->pdoException('SQLSTATE[42S22]: Column not found: 1054 Unknown column \'missing_col\' in \'field list\'', '42S22', 1054),
            debug: false,
        );

        $this->assertSame(ErrorCode::NOT_FOUND, $e->publicCode());
        $this->assertSame('{"code":404}', $e->getMessage());
        $this->assertStringNotContainsString('missing_col', $e->getMessage());
    }

    public function testUnknownTableDebugTrueExposesSqlState(): void
    {
        $raw = 'SQLSTATE[42S02]: Base table or view not found: 1146 Table \'db.ghost\' doesn\'t exist';
        $e = $this->queryExceptionFromPdo($this->pdoException($raw, '42S02', 1146), debug: true);

        $this->assertSame('Query failed: ' . $raw, $e->getMessage());
        $this->assertSame('{"code":404}', $e->toJson());
    }

    public function testFirstOrFailThrows404Json(): void
    {
        $builder = $this->builderWithEmptyResult(debug: false);

        try {
            $builder->from('account')->where('id', '=', 999)->firstOrFail();
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame(ErrorCode::NOT_FOUND, $e->publicCode());
            $this->assertSame('{"code":404}', $e->getMessage());
        }
    }

    public function testFirstOrFailDebugTrueExposesDetail(): void
    {
        $builder = $this->builderWithEmptyResult(debug: true);

        try {
            $builder->from('account')->where('id', '=', 999)->firstOrFail();
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertTrue($e->isDebug());
            $this->assertSame('Record not found', $e->getMessage());
            $this->assertSame('{"code":404}', $e->toJson());
        }
    }

    public function testFindByIdOrFailThrows404Json(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([]);
        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
        $pdo->method('prepare')->willReturn($statement);

        $schema = $this->accountSchema();
        $manager = new ConnectionManager(new ConnectionConfig('localhost'), new FakeConnectionStrategy($pdo));
        $mapper = new EntityMapper(new QueryBuilder($schema, $manager));
        $mapper->register(AccountDto::class, 'account', [
            'id' => 'id',
            'name' => 'name',
            'email' => 'email',
        ], ['name', 'email']);
        $repo = new AccountRepository($manager, $schema, $mapper);

        try {
            $repo->findByIdOrFail(999999);
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame(ErrorCode::NOT_FOUND, $e->publicCode());
            $this->assertSame('{"code":404}', $e->getMessage());
        }
    }

    public function testDeadlockTransactionMapsTo500(): void
    {
        $e = new TransactionException('Transaction failed: Deadlock found', '40001', 1213, null, false);

        $this->assertSame(ErrorCode::FAIL, $e->publicCode());
        $this->assertSame('{"code":500}', $e->getMessage());
        $this->assertSame('40001', $e->getSqlState());
    }

    public function testTransactionPreservesInnerPublicCode(): void
    {
        $inner = new QueryException(
            'Query failed: table missing',
            'SELECT * FROM ghost',
            [],
            '42S02',
            1146,
            null,
            false,
        );
        $outer = new TransactionException(
            'Transaction failed: ' . $inner->getMessage(),
            $inner->getSqlState(),
            $inner->getCode(),
            $inner,
            false,
        );

        $this->assertSame(ErrorCode::NOT_FOUND, $outer->publicCode());
        $this->assertSame('{"code":404}', $outer->getMessage());
    }

    public function testHy000AccessDeniedDriverCodeMapsTo403(): void
    {
        $pdoException = new PDOException('SQLSTATE[HY000] [1045] Access denied for user', 0);
        $pdoException->errorInfo = ['HY000', 1045, 'Access denied for user'];

        $sqlState = \Inclitoleo\Mysql\Connection\PdoFactory::sqlStateFrom($pdoException);
        $driver = \Inclitoleo\Mysql\Connection\PdoFactory::driverCodeFrom($pdoException);

        $this->assertSame(1045, $driver);
        $this->assertSame(ErrorCode::FORBIDDEN, ErrorCode::fromSqlState($sqlState, $driver));
    }

    public function testOkJson(): void
    {
        $this->assertSame('{"code":200}', ErrorCode::ok());
    }

    /**
     * @dataProvider exceptionContractProvider
     * @param class-string<MysqlException> $class
     */
    public function testExceptionPublicContract(
        string $class,
        int $expectedCode,
        bool $debug,
        string $expectedMessage,
    ): void {
        $e = $this->exceptionForContract($class, $debug);

        $this->assertSame($expectedCode, $e->publicCode());
        $this->assertSame($expectedMessage, $e->getMessage());
        $this->assertSame('{"code":' . $expectedCode . '}', $e->toJson());
        $this->assertSame($debug, $e->isDebug());
    }

    /**
     * @return array<string, array{0: class-string<MysqlException>, 1: int, 2: bool, 3: string}>
     */
    public static function exceptionContractProvider(): array
    {
        return [
            'query 500 json' => [QueryException::class, 500, false, '{"code":500}'],
            'query 500 debug' => [QueryException::class, 500, true, 'Query failed: SQLSTATE[23000]: duplicate'],
            'not found 404 json' => [NotFoundException::class, 404, false, '{"code":404}'],
            'not found 404 debug' => [NotFoundException::class, 404, true, 'Record not found'],
            'identifier 403 json' => [InvalidIdentifierException::class, 403, false, '{"code":403}'],
            'identifier 403 debug' => [InvalidIdentifierException::class, 403, true, 'Table is not registered: ghost'],
            'connection 403 json' => [ConnectionException::class, 403, false, '{"code":403}'],
            'connection 403 debug' => [ConnectionException::class, 403, true, 'Failed to connect to MySQL: access denied'],
            'config 500 json' => [ConfigurationException::class, 500, false, '{"code":500}'],
            'config 500 debug' => [ConfigurationException::class, 500, true, 'Connection port must be between 1 and 65535.'],
            'tx 500 json' => [TransactionException::class, 500, false, '{"code":500}'],
            'tx 500 debug' => [TransactionException::class, 500, true, 'Transaction failed: deadlock'],
        ];
    }

    /**
     * @param class-string<MysqlException> $class
     */
    private function exceptionForContract(string $class, bool $debug): MysqlException
    {
        return match ($class) {
            QueryException::class => new QueryException('Query failed: SQLSTATE[23000]: duplicate', 'INSERT', ['x'], '23000', 1062, null, $debug),
            NotFoundException::class => new NotFoundException('Record not found', $debug),
            InvalidIdentifierException::class => new InvalidIdentifierException('Table is not registered: ghost', 'ghost', 'whitelist', 0, null, $debug),
            ConnectionException::class => new ConnectionException('Failed to connect to MySQL: access denied', '28000', 1045, null, $debug),
            ConfigurationException::class => new ConfigurationException('Connection port must be between 1 and 65535.', 0, null, $debug),
            TransactionException::class => new TransactionException('Transaction failed: deadlock', '40001', 1213, null, $debug),
            default => throw new \InvalidArgumentException($class),
        };
    }

    private function accountSchema(): SchemaRegistry
    {
        $schema = new SchemaRegistry();
        $schema->register('account', ['id', 'name', 'email']);

        return $schema;
    }

    private function builderWithEmptyResult(bool $debug): QueryBuilder
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([]);

        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
        $pdo->method('prepare')->willReturn($statement);

        $manager = new ConnectionManager(
            new ConnectionConfig('localhost', debug: $debug),
            new FakeConnectionStrategy($pdo),
        );

        return new QueryBuilder($this->accountSchema(), $manager);
    }

    private function pdoException(string $message, string $sqlState, int $driverCode): PDOException
    {
        $pdoException = new PDOException($message, (int) $sqlState);
        $pdoException->errorInfo = [$sqlState, $driverCode, $message];

        return $pdoException;
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
