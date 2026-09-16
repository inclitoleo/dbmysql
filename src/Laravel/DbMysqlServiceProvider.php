<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Laravel;

use Inclitoleo\Mysql\Connection\ConnectionConfig;
use Inclitoleo\Mysql\Connection\ConnectionManager;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Security\SchemaRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class DbMysqlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2) . '/config/dbmysql.php', 'dbmysql');

        $this->app->singleton(ConnectionConfig::class, function (Application $app): ConnectionConfig {
            $name = (string) $app['config']->get('dbmysql.connection', 'mysql');
            /** @var array<string, mixed> $connection */
            $connection = $app['config']->get('database.connections.' . $name, []);
            $connection['debug'] = (bool) $app['config']->get('dbmysql.debug', false);

            return ConnectionConfig::fromArray($connection);
        });

        $this->app->singleton(ConnectionManager::class, function (Application $app): ConnectionManager {
            return new ConnectionManager($app->make(ConnectionConfig::class));
        });

        $this->app->singleton(SchemaRegistry::class, function (Application $app): SchemaRegistry {
            $schema = new SchemaRegistry();
            /** @var array<string, list<string>> $tables */
            $tables = $app['config']->get('dbmysql.tables', []);
            foreach ($tables as $table => $columns) {
                $schema->register((string) $table, array_values($columns));
            }

            return $schema;
        });

        $this->app->singleton(QueryBuilder::class, function (Application $app): QueryBuilder {
            return new QueryBuilder(
                $app->make(SchemaRegistry::class),
                $app->make(ConnectionManager::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            dirname(__DIR__, 2) . '/config/dbmysql.php' => config_path('dbmysql.php'),
        ], 'dbmysql-config');
    }
}
