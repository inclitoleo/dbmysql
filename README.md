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
    debug: false, // default: public JSON codes only
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

## Debug

`ConnectionConfig::$debug` defaults to **`false`**. Failures always throw `MysqlException`; what changes is the **public** message.

| `debug` | `getMessage()` | Use |
|---|---|---|
| `false` (default, production) | JSON only, e.g. `{"code":500}` | Safe to return to the client |
| `true` (local) | Raw driver text, e.g. `Query failed: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'leo@example.com' for key 'account.uq_account_email'` | Screen / logs while developing |

```php
use Inclitoleo\Mysql\Exception\ErrorCode;
use Inclitoleo\Mysql\Exception\MysqlException;

$manager = new ConnectionManager(new ConnectionConfig(
    host: '127.0.0.1',
    database: 'app',
    username: 'app',
    password: 'secret',
    debug: false, // set true only on a local machine
));

try {
    $builder->insert('account', ['name' => 'Leo', 'email' => 'leo@example.com']);
    echo ErrorCode::ok(); // {"code":200}
} catch (MysqlException $e) {
    echo $e->getMessage(); // debug=false → {"code":500}  |  debug=true → SQLSTATE cru
    echo $e->toJson();     // always {"code":N}
    // $e->getDetail(), getSql(), getBindings() — for your logger, never for the client
}
```

Public codes:

| Code | Meaning |
|---|---|
| `200` | Success helper (`ErrorCode::ok()`). Not thrown; you emit it after a successful call. |
| `403` | Identifier rejected or MySQL access denied |
| `404` | Missing table/column, or empty `firstOrFail()` / `findByIdOrFail()` |
| `500` | Query, constraint (duplicate key, FK), deadlock, or other operational failure |

SQL, bindings and PII stay off `getMessage()` unless `debug` is `true`. Keep `debug` off in production.

## Exceptions

| Class | When |
|---|---|
| `MysqlException` | Base type for all library failures |
| `ConfigurationException` | Invalid config before PDO |
| `ConnectionException` | PDO connect failure |
| `QueryException` | SQL execution failure (`getSql()`, `getBindings()`, `getSqlState()`) |
| `InvalidIdentifierException` | Table/column/operator rejected |
| `NotFoundException` | Empty `firstOrFail()` / `findByIdOrFail()` (`{"code":404}`) |
| `TransactionException` | Failure inside `transaction()` after rollback |
