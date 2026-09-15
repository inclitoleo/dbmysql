<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Unit\Query;

use Inclitoleo\Mysql\Exception\ConfigurationException;
use Inclitoleo\Mysql\Exception\InvalidIdentifierException;
use Inclitoleo\Mysql\Exception\QueryException;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Query\SortDirection;
use Inclitoleo\Mysql\Security\SchemaRegistry;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
    private SchemaRegistry $schema;

    private QueryBuilder $builder;

    protected function setUp(): void
    {
        $this->schema = new SchemaRegistry();
        $this->schema->register('account', ['id', 'name', 'email', 'department', 'salary']);
        $this->schema->register('orders', ['id', 'account_id', 'total']);
        $this->builder = new QueryBuilder($this->schema);
    }

    public function testSelectCompilationAndImmutability(): void
    {
        $q1 = $this->builder->from('account');
        $q2 = $q1->where('id', '=', 1);

        $this->assertSame('SELECT * FROM account', $q1->toSql());
        $this->assertSame([], $q1->bindings());
        $this->assertSame('SELECT * FROM account WHERE id = ?', $q2->toSql());
        $this->assertSame([1], $q2->bindings());
        $this->assertStringNotContainsString('WHERE', $q1->toSql());
    }

    public function testSelectSpecificColumns(): void
    {
        $sql = $this->builder->from('account')->select(['id', 'name'])->toSql();
        $this->assertSame('SELECT id, name FROM account', $sql);
    }

    public function testValuesArePlaceholdersOnly(): void
    {
        $q = $this->builder->from('account')->where('name', '=', "Robert'); DROP TABLE account;--");
        $this->assertSame('SELECT * FROM account WHERE name = ?', $q->toSql());
        $this->assertSame(["Robert'); DROP TABLE account;--"], $q->bindings());
        $this->assertStringNotContainsString("Robert'); DROP TABLE account;--", $q->toSql());
    }

    public function testOrderByAndLimit(): void
    {
        $q = $this->builder->from('account')->orderBy('name', SortDirection::DESC)->limit(10);
        $this->assertSame('SELECT * FROM account ORDER BY name DESC LIMIT ?', $q->toSql());
        $this->assertSame([10], $q->bindings());
    }

    public function testCursorPaginateDoesNotUseOffset(): void
    {
        $q = $this->builder->cursorPaginate('account', 'id', 100, 50);
        $sql = $q->toSql();
        $this->assertSame('SELECT * FROM account WHERE id > ? ORDER BY id ASC LIMIT ?', $sql);
        $this->assertSame([100, 50], $q->bindings());
        $this->assertStringNotContainsString('OFFSET', $sql);
    }

    public function testCteAndRowNumber(): void
    {
        $sub = $this->builder->from('account')->select(['id', 'name'])->where('id', '=', 1);
        $q = $this->builder
            ->with('active_users', $sub)
            ->from('active_users')
            ->select(['id'])
            ->rowNumber('rank', 'id', 'id');

        $this->assertSame(
            'WITH active_users AS (SELECT id, name FROM account WHERE id = ?) SELECT id, ROW_NUMBER() OVER (PARTITION BY id ORDER BY id) AS rank FROM active_users',
            $q->toSql(),
        );
        $this->assertSame([1], $q->bindings());
        $this->assertStringStartsWith('WITH active_users AS (', $q->toSql());
        $this->assertStringContainsString('ROW_NUMBER() OVER (PARTITION BY id ORDER BY id)', $q->toSql());
    }

    public function testWithRawSubqueryString(): void
    {
        $q = $this->builder->with('active_users', 'SELECT id FROM account')->from('active_users')->select(['id']);
        $this->assertSame('WITH active_users AS (SELECT id FROM account) SELECT id FROM active_users', $q->toSql());
    }

    public function testCompileInsertUpdateDeleteAndUpsert(): void
    {
        $insert = $this->builder->compileInsert('account', ['name' => 'Leo', 'email' => 'a@b.c']);
        $this->assertSame('INSERT INTO account (name, email) VALUES (?, ?)', $insert->sql);
        $this->assertSame(['Leo', 'a@b.c'], $insert->bindings);

        $batch = $this->builder->compileInsertBatch('account', [
            ['name' => 'A', 'email' => 'a@x'],
            ['name' => 'B', 'email' => 'b@x'],
        ]);
        $this->assertSame('INSERT INTO account (name, email) VALUES (?, ?), (?, ?)', $batch->sql);
        $this->assertCount(4, $batch->bindings);

        $update = $this->builder->compileUpdate('account', ['name' => 'Novo'], ['id' => 1]);
        $this->assertSame('UPDATE account SET name = ? WHERE id = ?', $update->sql);
        $this->assertSame(['Novo', 1], $update->bindings);

        $delete = $this->builder->compileDelete('account', ['id' => 1]);
        $this->assertSame('DELETE FROM account WHERE id = ?', $delete->sql);
        $this->assertSame([1], $delete->bindings);

        $upsert = $this->builder->compileUpsert('account', ['email' => 'a@b.c', 'name' => 'Leo'], ['name']);
        $this->assertSame(
            'INSERT INTO account (email, name) VALUES (?, ?) AS new ON DUPLICATE KEY UPDATE name = new.name',
            $upsert->sql,
        );
    }

    public function testInsertBatchChunkingWouldSplitRows(): void
    {
        $rows = [];
        for ($i = 0; $i < 1200; $i++) {
            $rows[] = ['name' => 'n' . $i, 'email' => 'e' . $i . '@x.test'];
        }
        $chunks = array_chunk($rows, 500);
        $this->assertCount(3, $chunks);
        $this->assertCount(500, $chunks[0]);
        $this->assertCount(500, $chunks[1]);
        $this->assertCount(200, $chunks[2]);
        $this->assertSame(500, substr_count($this->builder->compileInsertBatch('account', $chunks[0])->sql, '(?, ?)'));
    }

    public function testUnregisteredTableIsRejected(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->builder->from('users; DROP TABLE account');
    }

    public function testUnregisteredColumnIsRejected(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->builder->from('account')->select(['password_hash']);
    }

    public function testDeleteUnregisteredTableIsRejected(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->builder->compileDelete('secret_table', ['id' => 1]);
    }

    public function testInvalidOperatorIsRejected(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->builder->from('account')->where('id', 'OR 1=1', 1);
    }

    public function testSelectRequiresFrom(): void
    {
        $this->expectException(QueryException::class);
        $this->builder->toSql();
    }

    public function testUpdateRequiresWhere(): void
    {
        $this->expectException(QueryException::class);
        $this->builder->compileUpdate('account', ['name' => 'x'], []);
    }

    public function testNegativeLimitIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->builder->from('account')->limit(-1);
    }

    public function testInvalidSortDirectionIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->builder->from('account')->orderBy('id', 'SIDEWAYS');
    }

    public function testInOperatorExpandsPlaceholders(): void
    {
        $q = $this->builder->from('account')->where('id', 'IN', [1, 2, 3]);
        $this->assertSame('SELECT * FROM account WHERE id IN (?, ?, ?)', $q->toSql());
        $this->assertSame([1, 2, 3], $q->bindings());
    }

    public function testExecuteWithoutConnectionThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->builder->from('account')->get();
    }

    public function testRawStackedQueryIsRejected(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->builder->raw('SELECT * FROM account; DROP TABLE account', []);
    }

    public function testInsertBatchEmptyReturnsZero(): void
    {
        $this->assertSame(0, $this->builder->insertBatch('account', []));
    }

    public function testChunkSizeMustBePositive(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->builder->insertBatch('account', [['name' => 'a', 'email' => 'a@b.c']], 0);
    }

    public function testCursorPaginateRejectsNonPositiveLimit(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->builder->cursorPaginate('account', 'id', 0, 0);
    }

    public function testRawUnregisteredTableIsRejected(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->builder->raw('SELECT * FROM secret_table WHERE id = ?', [1]);
    }

    public function testWithStackedSubqueryIsRejected(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->builder->with('active_users', 'SELECT 1; DROP TABLE account');
    }

    public function testCompileInsertAcceptsObject(): void
    {
        $row = (object) ['name' => 'Leo', 'email' => 'a@b.c'];
        $compiled = $this->builder->compileInsert('account', $row);
        $this->assertSame('INSERT INTO account (name, email) VALUES (?, ?)', $compiled->sql);
    }

    public function testUpsertRequiresUpdateColumns(): void
    {
        $this->expectException(QueryException::class);
        $this->builder->compileUpsert('account', ['email' => 'a@b.c'], []);
    }

    public function testInsertBatchExecutesOneQueryPerChunk(): void
    {
        $rows = [];
        for ($i = 0; $i < 1200; $i++) {
            $rows[] = ['name' => 'n' . $i, 'email' => 'e' . $i . '@x.test'];
        }
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects($this->exactly(3))->method('execute')->willReturn(true);
        $statement->method('rowCount')->willReturnOnConsecutiveCalls(500, 500, 200);
        $pdo = $this->getMockBuilder(\PDO::class)->disableOriginalConstructor()->getMock();
        $pdo->method('prepare')->willReturn($statement);
        $manager = new \Inclitoleo\Mysql\Connection\ConnectionManager(
            new \Inclitoleo\Mysql\Connection\ConnectionConfig('localhost'),
            new \Inclitoleo\Mysql\Tests\Support\FakeConnectionStrategy($pdo),
        );
        $builder = new QueryBuilder($this->schema, $manager);
        $this->assertSame(1200, $builder->insertBatch('account', $rows, 500));
    }

    public function testWindowFunctionSqlContainsPartition(): void
    {
        $sql = $this->builder
            ->from('account')
            ->select(['id'])
            ->rowNumber('rank', 'department', 'salary')
            ->toSql();
        $this->assertStringContainsString('ROW_NUMBER() OVER (PARTITION BY department ORDER BY salary)', $sql);
    }
}
