<?php

declare(strict_types=1);

use Inclitoleo\Mysql\Connection\ConnectionConfig;
use Inclitoleo\Mysql\Connection\ConnectionManager;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Security\SchemaRegistry;

function dbmysql_lab_builder(): QueryBuilder
{
    $package = dirname(__DIR__) . '/vendor/inclitoleo/dbmysql/composer.json';
    if (!is_file($package)) {
        fwrite(STDERR, "inclitoleo/dbmysql is not installed in vendor/. Run composer install.\n");
        exit(1);
    }

    $manager = new ConnectionManager(new ConnectionConfig(
        host: getenv('MYSQL_HOST') ?: 'mysql',
        port: (int) (getenv('MYSQL_PORT') ?: '3306'),
        database: getenv('MYSQL_DATABASE') ?: 'dbmysql_lab',
        username: getenv('MYSQL_USER') ?: 'dbmysql',
        password: getenv('MYSQL_PASSWORD') ?: 'dbmysql',
    ));

    $schema = new SchemaRegistry();
    $schema->register('account', ['id', 'name', 'email']);

    return new QueryBuilder($schema, $manager);
}
