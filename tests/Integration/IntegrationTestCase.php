<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Integration;

use Inclitoleo\Mysql\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (TestDatabase::config() === null) {
            $this->markTestSkipped('Set DBMYSQL_TEST_DSN or MYSQL_HOST to run integration tests.');
        }

        $pdo = TestDatabase::manager()->pdo();
        TestDatabase::ensureSchema($pdo);
        TestDatabase::reset($pdo);
    }
}
