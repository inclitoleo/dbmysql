<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Unit\Exception;

use Inclitoleo\Mysql\Exception\ConfigurationException;
use Inclitoleo\Mysql\Exception\ConnectionException;
use Inclitoleo\Mysql\Exception\MysqlException;
use Inclitoleo\Mysql\Exception\InvalidIdentifierException;
use Inclitoleo\Mysql\Exception\QueryException;
use Inclitoleo\Mysql\Exception\TransactionException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExceptionHierarchyTest extends TestCase
{
    public function testQueryExceptionExposesSqlBindingsAndSqlState(): void
    {
        $previous = new RuntimeException('pdo');
        $e = new QueryException('failed', 'SELECT id FROM account WHERE id = ?', [1], '42000', 42000, $previous);

        $this->assertInstanceOf(MysqlException::class, $e);
        $this->assertSame('SELECT id FROM account WHERE id = ?', $e->getSql());
        $this->assertSame([1], $e->getBindings());
        $this->assertSame('42000', $e->getSqlState());
        $this->assertSame($previous, $e->getPrevious());
        $this->assertNotSame('', $e->getSql());
        $this->assertNotSame([], $e->getBindings());
    }

    public function testInvalidIdentifierExceptionContainsRejectedNameAndReason(): void
    {
        $e = new InvalidIdentifierException('bad table', 'account; DROP', 'identifier contains a semicolon');

        $this->assertInstanceOf(MysqlException::class, $e);
        $this->assertSame('account; DROP', $e->getIdentifier());
        $this->assertSame('identifier contains a semicolon', $e->getReason());
    }

    public function testConnectionExceptionCarriesSqlState(): void
    {
        $e = new ConnectionException('denied', '28000', 1045);

        $this->assertInstanceOf(MysqlException::class, $e);
        $this->assertSame('28000', $e->getSqlState());
        $this->assertSame(1045, $e->getCode());
    }

    public function testTransactionExceptionCarriesDeadlockSqlState(): void
    {
        $e = new TransactionException('deadlock', '40001');

        $this->assertInstanceOf(MysqlException::class, $e);
        $this->assertSame('40001', $e->getSqlState());
    }

    public function testConfigurationExceptionIsMysqlException(): void
    {
        $e = new ConfigurationException('port');

        $this->assertInstanceOf(MysqlException::class, $e);
        $this->assertSame('port', $e->getMessage());
    }

    public function testExceptionsPropagateThroughCatchOfBaseType(): void
    {
        try {
            throw new QueryException('boom', 'SELECT 1', [true], 'HY000');
        } catch (MysqlException $e) {
            $this->assertInstanceOf(QueryException::class, $e);
            $this->assertSame('SELECT 1', $e->getSql());
            $this->assertSame('HY000', $e->getSqlState());
            return;
        }
    }
}
