<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Integration;

use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Tests\Support\TestDatabase;

final class QueryBuilderTest extends IntegrationTestCase
{
    public function testBatchInsertInsertsAllRows(): void
    {
        $builder = TestDatabase::builder();
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $rows[] = ['name' => 'Batch ' . $i, 'email' => 'batch' . $i . '@example.com'];
        }
        $builder->insertBatch('account', $rows, 50);
        $count = (int) TestDatabase::manager()->pdo()->query('SELECT COUNT(*) FROM account')->fetchColumn();
        $this->assertSame(101, $count);
    }

    public function testUpsertUpdatesExistingRow(): void
    {
        $builder = TestDatabase::builder();
        $builder->upsert('account', ['email' => 'seed@example.com', 'name' => 'First'], ['name']);
        $builder->upsert('account', ['email' => 'seed@example.com', 'name' => 'Updated'], ['name']);
        $row = $builder->from('account')->where('email', '=', 'seed@example.com')->first();
        $this->assertNotNull($row);
        $this->assertSame('Updated', $row->name);
        $count = (int) TestDatabase::manager()->pdo()->query("SELECT COUNT(*) FROM account WHERE email = 'seed@example.com'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCursorPaginationReturnsNextPage(): void
    {
        $builder = TestDatabase::builder();
        $builder->insert('account', ['name' => 'P2', 'email' => 'p2@example.com']);
        $page = $builder->cursorPaginate('account', 'id', 1, 50)->get();
        $this->assertNotEmpty($page);
        foreach ($page as $row) {
            $this->assertGreaterThan(1, (int) $row->id);
        }
        $this->assertStringNotContainsString('OFFSET', $builder->cursorPaginate('account', 'id', 1, 50)->toSql());
    }

    public function testCursorStreamsWithoutHoldingFullResult(): void
    {
        $builder = TestDatabase::builder();
        $rows = [];
        $total = (int) (getenv('DBMYSQL_STREAM_ROWS') ?: 1000);
        for ($i = 0; $i < $total; $i++) {
            $rows[] = ['message' => 'log-' . $i];
        }
        $builder->insertBatch('logs', $rows, 500);

        $baseline = memory_get_usage(true);
        $count = 0;
        $peak = $baseline;
        foreach ($builder->from('logs')->cursor() as $row) {
            $count++;
            $peak = max($peak, memory_get_usage(true));
            $this->assertIsObject($row);
        }
        $threshold = (int) (getenv('DBMYSQL_STREAM_MEMORY_BYTES') ?: (8 * 1024 * 1024));
        $this->assertGreaterThan($total, $count);
        $this->assertLessThan($baseline + $threshold, $peak);
    }

    public function testExplainReturnsPlanRows(): void
    {
        $plan = TestDatabase::builder()->from('account')->where('id', '=', 1)->explain();
        $this->assertNotEmpty($plan);
        $this->assertIsObject($plan[0]);
    }

    public function testMaliciousWhereValueDoesNotDropTable(): void
    {
        $builder = TestDatabase::builder();
        $builder->from('account')->where('name', '=', "Robert'); DROP TABLE account;--")->get();
        $count = (int) TestDatabase::manager()->pdo()->query('SELECT COUNT(*) FROM account')->fetchColumn();
        $this->assertGreaterThan(0, $count);
    }

    public function testCteQueryRuns(): void
    {
        $schema = TestDatabase::schema();
        $sub = (new QueryBuilder($schema))->from('account')->select(['id', 'name'])->where('id', '=', 1);
        $rows = TestDatabase::builder()
            ->with('active_users', $sub)
            ->from('active_users')
            ->select(['id', 'name'])
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Seed User', $rows[0]->name);
    }
}
