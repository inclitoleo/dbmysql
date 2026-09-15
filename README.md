# dbmysql

Simple, fast and objective PHP library for connecting to MySQL and running parameterized queries.

Package: `inclitoleo/dbmysql`  
Namespace: `Inclitoleo\Mysql\`  
PHP: `>= 8.1`  
License: GPL-3.0-or-later

Existing `MySqlClient` callers keep working through a deprecated compatibility facade. New code should use `ConnectionManager` and `QueryBuilder`.

## Install

```shell
composer require inclitoleo/dbmysql
```

## Architecture (v3)

```
Application
    ↓
Repository (opt-in)
    ↓
QueryBuilder  ←  SchemaRegistry + IdentifierValidator
    ↓
ConnectionManager → PDO
    ↓
ProxySQL / MySQL 8.x
```

- **ConnectionManager** — injectable `ConnectionConfig`, single or persistent strategies, optional read/write split, `transaction()`.
- **QueryBuilder** — immutable SELECT composition, batch insert, upsert, cursor pagination, streaming, CTEs, window functions.
- **SchemaRegistry** — whitelist of table/column identifiers. Unregistered or invalid identifiers throw `InvalidIdentifierException` before SQL runs.
- **EntityMapper / Repository** — opt-in DTO mapping. No Active Record, no lazy load.
- **MySqlClient** — deprecated v2 facade over the v3 layer.

v3 never prints errors. Failures throw typed exceptions (`MysqlException` and subclasses). Values always go through native prepared statements (`PDO::ATTR_EMULATE_PREPARES = false`).

## v3 quick start

```php
use Inclitoleo\Mysql\Connection\ConnectionConfig;
use Inclitoleo\Mysql\Connection\ConnectionManager;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Query\SortDirection;
use Inclitoleo\Mysql\Security\SchemaRegistry;

$manager = new ConnectionManager(new ConnectionConfig(
    host: '127.0.0.1',
    port: 3306,
    database: 'app',
    username: 'app',
    password: 'secret',
));

$schema = new SchemaRegistry();
$schema->register('account', ['id', 'name', 'email']);

$builder = new QueryBuilder($schema, $manager);

$rows = $builder
    ->from('account')
    ->select(['id', 'name', 'email'])
    ->where('id', '=', 1)
    ->orderBy('name', SortDirection::ASC)
    ->limit(10)
    ->get();
```

### Schema whitelist

Identifiers must match `^[a-zA-Z_][a-zA-Z0-9_]*$` **and** be registered:

```php
$schema->register('account', ['id', 'name', 'email']);
$schema->register('orders', ['id', 'account_id', 'total']);

$builder->from('account')->select(['id', 'name']); // ok
$builder->from('account; DROP TABLE users');       // InvalidIdentifierException
$builder->from('account')->select(['password_hash']); // InvalidIdentifierException
```

Register tables once at bootstrap. `MySqlClient` auto-registers identifiers that pass the charset check so v2 callers do not need a manual registry.

### Transactions, batch, upsert, cursor pagination

```php
$manager->transaction(function (ConnectionManager $m) use ($builder): void {
    $builder->insert('account', ['name' => 'Ada', 'email' => 'ada@example.com']);
    $builder->insertBatch('account', $rows, chunkSize: 500);
    $builder->upsert('account', ['email' => 'ada@example.com', 'name' => 'Ada L.'], ['name']);
});

$page = $builder->cursorPaginate('account', 'id', after: 100, limit: 50)->get();

foreach ($builder->from('logs')->cursor() as $row) {
    // unbuffered generator
}
```

### Read / write split and ProxySQL

```php
$manager->registerEndpoint('primary', $primaryConfig);
$manager->registerEndpoint('replica', $replicaConfig);

$write = $manager->connectionFor('write'); // always primary
$read  = $manager->connectionFor('read');  // replica if registered, otherwise primary
```

This library does not implement an in-process connection pool. For pooling under PHP-FPM, put **ProxySQL** or **MySQL Router** in front of MySQL and point `ConnectionConfig` at the proxy host/port. Persistent PDO (`ConnectionConfig::$persistent` / `PersistentConnectionStrategy`) is available when the process model benefits from it.

Optional PSR-3 logger:

```php
$manager = new ConnectionManager($config, logger: $psrLogger);
```

Errors are logged at `error` with SQL and SQLSTATE when available.

## Migrate from v2 (`MySqlClient`)

v2 code still works:

```php
use Inclitoleo\Mysql\database\MySqlClient;

$db = new MySqlClient();
$id = $db->insert('account', $obj);
```

`MySqlClient` is **deprecated**. Internally it translates `INCLITO*` constants to `ConnectionConfig` and delegates to v3.

| v2 | v3 |
|---|---|
| `INCLITOHOST` / `INCLITOPORT` | `ConnectionConfig(host, port)` |
| `INCLITODBNAME` / `INCLITOUSER` / `INCLITOPWD` | `database` / `username` / `password` |
| `INCLITOTYPECONN` | `persistent` |
| `INCLITOBOOLCERT` + CA/cert/key | `sslEnabled` + `SslConfig` |
| `echo` + `false` on error | typed exceptions |
| concatenated identifiers | `SchemaRegistry` whitelist |

Host may still be `localhost:3306`. If `INCLITOPORT` is undefined, the port defaults to `3306` (or the `:port` suffix on the host).

**Compat mode (default):** catch v3 exceptions, `echo` the message, return `false` — same as v2.

**Strict mode:** define `INCLITOSTRICT` as `true` to rethrow:

```php
const INCLITOSTRICT = true;
```

Recommended replacement:

```php
// before
$db = new MySqlClient();
$row = $db->select('account', 'id', 1);

// after
$row = $builder->from('account')->where('id', '=', 1)->first();
```

## Connection constants (v2 facade)

```php
const INCLITOHOST = 'localhost:3306';
const INCLITOPORT = 3306; // optional; default 3306 if undefined
const INCLITODBNAME = '';
const INCLITOUSER = '';
const INCLITOPWD = '';
const INCLITODRIVER = 'mysql';
const INCLITOTYPECONN = false;
const INCLITOBOOLCERT = false;
const INCLITOMYCA = '';
const INCLITOMYCERT = '';
const INCLITOMYKEY = '';
const INCLITOSTRICT = false;
```

## CRUD lab (Docker)

Sobe MySQL 8.4 e um app PHP que instala `inclitoleo/dbmysql` via Composer (path repository, cópia em `vendor/` — sem symlink). Não publica no Packagist.

```shell
docker compose -f docker-compose.sandbox.yml up --build
```

- Smoke test CRUD no log do container `app`
- UI: http://localhost:8088
- MySQL: `127.0.0.1:3311` (user/password `dbmysql`, database `dbmysql_lab`)

## Tests

PHPUnit 10 suites: `unit`, `integration`, `security`, `regression`.

```shell
composer install
composer test-unit          # no MySQL required
composer test-security      # identifier tests always; payload tests skip without DB
composer test-integration   # needs MySQL 8.4
composer test-regression
composer test
```

Local MySQL for integration/regression/security payloads:

```shell
docker compose -f docker-compose.test.yml up -d
export MYSQL_HOST=127.0.0.1 MYSQL_PORT=3310 MYSQL_DATABASE=dbmysql_test MYSQL_USER=dbmysql MYSQL_PASSWORD=dbmysql
composer test
```

`docker-compose.test.yml` starts MySQL 8.4 with database `dbmysql_test` and seed tables `account`, `orders`, and `logs`.

Alternatively set `DBMYSQL_TEST_DSN` (`mysql:host=127.0.0.1;port=3310;dbname=dbmysql_test`) plus `MYSQL_USER` / `MYSQL_PASSWORD`.

Streaming memory tests honor `DBMYSQL_STREAM_ROWS` (default 1000) and `DBMYSQL_STREAM_MEMORY_BYTES` (default 8 MiB).

CI (GitHub Actions) runs PHP 8.1 / 8.2 / 8.3: lint, unit, integration against MySQL 8.4, security, regression, and v3 coverage (`>= 90%` on `Connection`, `Query`, `Security`, `Exception`, `Mapper`).

## Exceptions

| Class | When |
|---|---|
| `MysqlException` | Base type for all library failures |
| `ConfigurationException` | Invalid config before PDO |
| `ConnectionException` | PDO connect failure |
| `QueryException` | SQL execution failure (`getSql()`, `getBindings()`, `getSqlState()`) |
| `InvalidIdentifierException` | Table/column/operator rejected |
| `TransactionException` | Failure inside `transaction()` after rollback |
