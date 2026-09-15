<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Security;

use Inclitoleo\Mysql\Tests\Integration\IntegrationTestCase;
use Inclitoleo\Mysql\Tests\Support\TestDatabase;

final class SqlInjectionPayloadTest extends IntegrationTestCase
{
    public function testOrOneEqualsOneDoesNotLeakRows(): void
    {
        $builder = TestDatabase::builder();
        $rows = $builder->from('account')->where('name', '=', "' OR 1=1 --")->get();
        $this->assertCount(0, $rows);
        $literal = $builder->from('account')->where('name', '=', 'Seed User')->get();
        $this->assertCount(1, $literal);
        $count = (int) TestDatabase::manager()->pdo()->query('SELECT COUNT(*) FROM account')->fetchColumn();
        $this->assertGreaterThan(0, $count);
    }

    public function testUnionSelectPayloadDoesNotLeakOtherTables(): void
    {
        $builder = TestDatabase::builder();
        $rows = $builder->from('account')->where(
            'name',
            '=',
            "' UNION SELECT user, password FROM mysql.user --",
        )->get();
        $this->assertCount(0, $rows);
        foreach ($rows as $row) {
            $this->assertObjectNotHasProperty('password', $row);
        }
    }

    public function testClassicDropTablePayloadIsBoundAsValue(): void
    {
        $builder = TestDatabase::builder();
        $builder->from('account')->where('email', '=', "Robert'); DROP TABLE account;--")->get();
        $exists = (int) TestDatabase::manager()->pdo()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'account'")->fetchColumn();
        $this->assertSame(1, $exists);
    }
}
