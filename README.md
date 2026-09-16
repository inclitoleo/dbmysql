# dbmysql

Simple, fast MySQL library for PHP. Write SQL when you want SQL. Use the builder only when it saves you time. Works beside **Laravel**, **CakePHP**, and **Symfony** — it does not replace Eloquent, Cake ORM, or Doctrine.

Package: `inclitoleo/dbmysql`  
Namespace: `Inclitoleo\Mysql\`  
PHP: `>= 8.1`  
License: GPL-3.0-or-later

Existing `MySqlClient` callers keep working through a deprecated compatibility facade.

## Install

```shell
composer require inclitoleo/dbmysql:^3.0
```

## Connect once

Register tables once at bootstrap. After that, pass a full query or use the builder — same connection.

```php
use Inclitoleo\Mysql\Connection\ConnectionConfig;
use Inclitoleo\Mysql\Connection\ConnectionManager;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Security\SchemaRegistry;

$manager = new ConnectionManager(new ConnectionConfig(
    host: '127.0.0.1',
    port: 3306,
    database: 'app',
    username: 'app',
    password: 'secret',
    debug: false,
));

$schema = new SchemaRegistry();
$schema->register('account', ['id', 'name', 'email']);
$schema->register('orders', ['id', 'account_id', 'total']);
$schema->register('logs', ['id', 'message', 'created_at']);

$builder = new QueryBuilder($schema, $manager);
```

## Laravel, CakePHP, Symfony

Same `QueryBuilder`. Credentials come from the framework. Register tables once, then `raw()` or the builder.

### Laravel

Auto-discovery registers `QueryBuilder`. Publish optional table config:

```shell
php artisan vendor:publish --tag=dbmysql-config
```

```php
// config/dbmysql.php
'tables' => [
    'account' => ['id', 'name', 'email'],
    'orders' => ['id', 'account_id', 'total'],
],
'debug' => false,
```

```php
use Inclitoleo\Mysql\Query\QueryBuilder;

public function index(QueryBuilder $db)
{
    return $db->raw('SELECT * FROM account WHERE id = ?', [1]);
}
```

Uses `config/database.php` (`DB_*` / `database.connections.mysql`) by default.

Without auto-discovery:

```php
$config = ConnectionConfig::fromArray(config('database.connections.mysql'));
```

### CakePHP

```php
use Cake\Core\Configure;
use Inclitoleo\Mysql\Connection\ConnectionConfig;
use Inclitoleo\Mysql\Connection\ConnectionManager;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Security\SchemaRegistry;

$config = ConnectionConfig::fromArray(Configure::read('Datasources.default'));
$schema = new SchemaRegistry();
$schema->register('account', ['id', 'name', 'email']);
$builder = new QueryBuilder($schema, new ConnectionManager($config));

$accounts = $builder->raw('SELECT * FROM account');
```

`Datasources.default` in `app.php` / `app_local.php` (`host`, `username`, `password`, `database`, `encoding`) maps as-is.

### Symfony

`DATABASE_URL` from `.env`:

```env
DATABASE_URL="mysql://app:!Passw0rd@127.0.0.1:3306/app?charset=utf8mb4"
```

```yaml
# config/services.yaml
Inclitoleo\Mysql\Connection\ConnectionConfig:
    factory: ['Inclitoleo\Mysql\Connection\ConnectionConfig', 'fromDsn']
    arguments: ['%env(DATABASE_URL)%']

Inclitoleo\Mysql\Connection\ConnectionManager:
    arguments: ['@Inclitoleo\Mysql\Connection\ConnectionConfig']

Inclitoleo\Mysql\Security\SchemaRegistry: ~

Inclitoleo\Mysql\Query\QueryBuilder:
    arguments: ['@Inclitoleo\Mysql\Security\SchemaRegistry', '@Inclitoleo\Mysql\Connection\ConnectionManager']
```

```php
public function index(QueryBuilder $db): JsonResponse
{
    return $this->json($db->raw('SELECT * FROM account'));
}
```

Register tables in a compiler pass, a kernel boot listener, or a small decorator — same `SchemaRegistry::register()` as a plain PHP app.

Doctrine connection arrays work too: `ConnectionConfig::fromArray($doctrineParams)` (`dbname`, `user`, `host`, `port`, `charset`).

Values always go through native prepared statements (`PDO::ATTR_EMULATE_PREPARES = false`). Failures throw typed exceptions — v3 never `echo`s errors.

## Run SQL as you wrote it

You do **not** have to go through `from()` / `where()` / `select()`. `raw()` executes the statement as-is. Bind values with `?`.

```php
$accounts = $builder->raw('SELECT * FROM account');

$one = $builder->raw('SELECT * FROM account WHERE id = ?', [1]);

$builder->raw('UPDATE account SET name = ? WHERE id = ?', ['Ada', 1]);
```

`SELECT` / `WITH` / `SHOW` / `EXPLAIN` return a list of objects. Other statements return the affected row count.

### Join

There is no join builder. Pass the join in the SQL:

```php
$rows = $builder->raw(
    'SELECT a.id, a.name, o.total
     FROM account a
     INNER JOIN orders o ON o.account_id = a.id
     WHERE a.id = ?',
    [1],
);

$left = $builder->raw(
    'SELECT a.name, o.total
     FROM account a
     LEFT JOIN orders o ON o.account_id = a.id',
);
```

Tables named after `FROM` / `JOIN` / `INTO` / `UPDATE` must be registered. Table aliases (`a`, `o`) are ignored.

### CTE

Same idea — the whole query, including `WITH`:

```php
$rows = $builder->raw(
    'WITH recent AS (
         SELECT account_id, SUM(total) AS spent
         FROM orders
         GROUP BY account_id
     )
     SELECT a.name, r.spent
     FROM account a
     INNER JOIN recent r ON r.account_id = a.id',
);
```

CTE names (`recent`) do not need to be registered. Base tables (`account`, `orders`) do.

Or compose the CTE with the builder and keep the outer query small:

```php
$recent = (new QueryBuilder($schema))
    ->from('orders')
    ->select(['account_id', 'total']);

$rows = $builder
    ->with('recent', $recent)
    ->from('recent')
    ->select(['account_id', 'total'])
    ->get();

// string subquery also works
$rows = $builder
    ->with('recent', 'SELECT account_id, SUM(total) AS spent FROM orders GROUP BY account_id')
    ->from('recent')
    ->select(['account_id', 'spent'])
    ->get();
```

Window function:

```php
$rows = $builder
    ->from('account')
    ->select(['id', 'name'])
    ->rowNumber('rank', partitionBy: 'id', orderBy: 'id')
    ->get();
// SELECT id, name, ROW_NUMBER() OVER (PARTITION BY id ORDER BY id) AS rank FROM account
```

## Query builder (optional)

Use it when chaining is shorter than writing SQL. Skip it when it is not.

```php
use Inclitoleo\Mysql\Query\SortDirection;

$rows = $builder
    ->from('account')
    ->select(['id', 'name', 'email'])
    ->where('id', '=', 1)
    ->orderBy('name', SortDirection::ASC)
    ->limit(10)
    ->get();

$one = $builder->from('account')->where('email', '=', 'ada@example.com')->first();
$id = $builder->insert('account', ['name' => 'Ada', 'email' => 'ada@example.com']);
```

### Schema whitelist

Identifiers must match `^[a-zA-Z_][a-zA-Z0-9_]*$` **and** be registered (this applies to `raw()` too):

```php
$builder->from('account')->select(['id', 'name']); // ok
$builder->raw('SELECT * FROM account');           // ok
$builder->from('account; DROP TABLE users');      // InvalidIdentifierException
$builder->raw('SELECT * FROM account; DROP TABLE account'); // InvalidIdentifierException
```

`MySqlClient` auto-registers identifiers that pass the charset check so v2 callers do not need a manual registry.

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

## Architecture

```
Application
    ↓
QueryBuilder.raw(sql)  or  from()/where()/get()
    ↓
SchemaRegistry (whitelist) + IdentifierValidator
    ↓
ConnectionManager → PDO → MySQL 8.x / ProxySQL
```

Entity mapper / `Repository` are opt-in. No Active Record, no lazy load.

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
$all = $db->select_all('SELECT * FROM account WHERE id = 1', 'A');

// after — same SQL you already had
$all = $builder->raw('SELECT * FROM account WHERE id = ?', [1]);

// or the builder, if you prefer
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
