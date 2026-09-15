<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Query;

use Generator;
use Inclitoleo\Mysql\Connection\ConnectionManager;
use Inclitoleo\Mysql\Exception\ConfigurationException;
use Inclitoleo\Mysql\Exception\InvalidIdentifierException;
use Inclitoleo\Mysql\Exception\NotFoundException;
use Inclitoleo\Mysql\Exception\QueryException;
use Inclitoleo\Mysql\Security\IdentifierValidator;
use Inclitoleo\Mysql\Security\SchemaRegistry;
use Psr\Log\LoggerInterface;
use ValueError;

final class QueryBuilder
{
    private const OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN'];

    private ?QueryExecutor $executor;

    private string $from = '';

    /** @var list<string> */
    private array $selectColumns = ['*'];

    /** @var list<array{0: string, 1: string, 2: mixed}> */
    private array $wheres = [];

    /** @var list<array{0: string, 1: SortDirection}> */
    private array $orderBys = [];

    private ?int $limit = null;

    /** @var array<string, array{sql: string, bindings: list<mixed>}> */
    private array $ctes = [];

    /** @var list<array{alias: string, partitionBy: string, orderBy: string}> */
    private array $windows = [];

    public function __construct(
        private readonly SchemaRegistry $schema,
        private readonly ?ConnectionManager $connection = null,
        private readonly ?LoggerInterface $logger = null,
        ?QueryExecutor $executor = null,
    ) {
        $this->executor = $executor ?? ($connection !== null
            ? new QueryExecutor($connection, $logger ?? $connection->logger())
            : null);
    }

    public function from(string $table): self
    {
        $this->assertSource($table);
        $clone = $this->fork(['from' => $table]);
        $clone->assertSelectedColumns();

        return $clone;
    }

    /**
     * @param list<string> $columns
     */
    public function select(array $columns): self
    {
        if ($columns === []) {
            $columns = ['*'];
        }
        $clone = $this->fork(['selectColumns' => array_values($columns)]);
        $clone->assertSelectedColumns();

        return $clone;
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        $operator = strtoupper(trim($operator));
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidIdentifierException(
                'Invalid SQL operator: ' . $operator,
                $operator,
                'operator is not in the allowed whitelist',
            );
        }
        $this->assertColumn($column);
        $wheres = $this->wheres;
        $wheres[] = [$column, $operator, $value];

        return $this->fork(['wheres' => $wheres]);
    }

    public function orderBy(string $column, SortDirection|string $direction = SortDirection::ASC): self
    {
        $this->assertColumn($column);
        if (is_string($direction)) {
            try {
                $direction = SortDirection::from(strtoupper($direction));
            } catch (ValueError $e) {
                throw new ConfigurationException('Invalid sort direction: ' . $direction, 0, $e);
            }
        }
        $orderBys = $this->orderBys;
        $orderBys[] = [$column, $direction];

        return $this->fork(['orderBys' => $orderBys]);
    }

    public function limit(int $n): self
    {
        if ($n < 0) {
            throw new ConfigurationException('LIMIT must be greater than or equal to 0.');
        }

        return $this->fork(['limit' => $n]);
    }

    /**
     * @param array<string, mixed>|object $data
     */
    public function insert(string $table, array|object $data): int|string
    {
        $compiled = $this->compileInsert($table, $data);
        $this->requireExecutor()->execute($compiled->sql, $compiled->bindings, 'write');

        return $this->requireExecutor()->lastInsertId('write');
    }

    /**
     * @param list<array<string, mixed>|object> $rows
     */
    public function insertBatch(string $table, array $rows, int $chunkSize = 500): int
    {
        if ($chunkSize < 1) {
            throw new ConfigurationException('chunkSize must be at least 1.');
        }
        if ($rows === []) {
            return 0;
        }

        $affected = 0;
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $compiled = $this->compileInsertBatch($table, $chunk);
            $affected += $this->requireExecutor()->execute($compiled->sql, $compiled->bindings, 'write');
        }

        return $affected;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        $compiled = $this->compileUpdate($table, $data, $where);

        return $this->requireExecutor()->execute($compiled->sql, $compiled->bindings, 'write');
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        $compiled = $this->compileDelete($table, $where);

        return $this->requireExecutor()->execute($compiled->sql, $compiled->bindings, 'write');
    }

    /**
     * @param array<string, mixed>|object $data
     * @param list<string> $updateColumns
     */
    public function upsert(string $table, array|object $data, array $updateColumns): int
    {
        $compiled = $this->compileUpsert($table, $data, $updateColumns);

        return $this->requireExecutor()->execute($compiled->sql, $compiled->bindings, 'write');
    }

    public function cursorPaginate(string $table, string $cursorColumn, mixed $after, int $limit): self
    {
        $this->schema->requireTable($table);
        $this->schema->requireColumn($table, $cursorColumn);
        if ($limit < 1) {
            throw new ConfigurationException('cursorPaginate limit must be at least 1.');
        }

        return $this
            ->from($table)
            ->where($cursorColumn, '>', $after)
            ->orderBy($cursorColumn, SortDirection::ASC)
            ->limit($limit);
    }

    /**
     * @return Generator<int, object>
     */
    public function cursor(): Generator
    {
        yield from $this->requireExecutor()->cursor($this->toSql(), $this->bindings());
    }

    public function with(string $name, string|self $subquery): self
    {
        IdentifierValidator::requireValid($name);
        if (is_string($subquery)) {
            $this->assertNoStackedQueries($subquery);
            $sql = $subquery;
            $bindings = [];
        } else {
            $sql = $subquery->toSql();
            $bindings = $subquery->bindings();
        }
        $ctes = $this->ctes;
        $ctes[$name] = ['sql' => $sql, 'bindings' => $bindings];

        return $this->fork(['ctes' => $ctes]);
    }

    public function rowNumber(string $alias, string $partitionBy, string $orderBy): self
    {
        IdentifierValidator::requireValid($alias);
        $this->assertColumn($partitionBy);
        $this->assertColumn($orderBy);
        $windows = $this->windows;
        $windows[] = [
            'alias' => $alias,
            'partitionBy' => $partitionBy,
            'orderBy' => $orderBy,
        ];

        return $this->fork(['windows' => $windows]);
    }

    /**
     * @return list<object>
     */
    public function explain(): array
    {
        return $this->requireExecutor()->fetchAll('EXPLAIN ' . $this->toSql(), $this->bindings(), 'read');
    }

    public function toSql(): string
    {
        return $this->compileSelect()->sql;
    }

    /**
     * @return list<mixed>
     */
    public function bindings(): array
    {
        return $this->compileSelect()->bindings;
    }

    /**
     * @param list<mixed>|array<string, mixed> $bindings
     */
    public function raw(string $sql, array $bindings = []): mixed
    {
        $this->assertRawSql($sql);
        if ($this->isReadSql($sql)) {
            return $this->requireExecutor()->fetchAll($sql, $bindings, 'read');
        }

        return $this->requireExecutor()->execute($sql, $bindings, 'write');
    }

    /**
     * @return list<object>
     */
    public function get(): array
    {
        return $this->requireExecutor()->fetchAll($this->toSql(), $this->bindings(), 'read');
    }

    public function first(): ?object
    {
        $rows = $this->limit(1)->get();

        return $rows[0] ?? null;
    }

    public function firstOrFail(): object
    {
        $row = $this->first();
        if ($row === null) {
            throw new NotFoundException('Record not found', $this->isDebug());
        }

        return $row;
    }

    /**
     * @param array<string, mixed>|object $data
     */
    public function compileInsert(string $table, array|object $data): CompiledQuery
    {
        $row = $this->normalizeRow($data);
        if ($row === []) {
            throw new QueryException('insert requires at least one column', '', []);
        }

        return $this->compileInsertBatch($table, [$row]);
    }

    /**
     * @param list<array<string, mixed>|object> $rows
     */
    public function compileInsertBatch(string $table, array $rows): CompiledQuery
    {
        $this->schema->requireTable($table);
        if ($rows === []) {
            throw new QueryException('insertBatch requires at least one row', '', []);
        }

        $first = $this->normalizeRow($rows[0]);
        $columns = array_keys($first);
        if ($columns === []) {
            throw new QueryException('insertBatch rows must contain columns', '', []);
        }
        foreach ($columns as $column) {
            $this->schema->requireColumn($table, (string) $column);
        }

        $rowPlaceholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $valueSets = [];
        $bindings = [];
        foreach ($rows as $row) {
            $normalized = $this->normalizeRow($row);
            $valueSets[] = $rowPlaceholders;
            foreach ($columns as $column) {
                $bindings[] = $normalized[$column] ?? null;
            }
        }

        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES ' . implode(', ', $valueSets);

        return new CompiledQuery($sql, $bindings);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function compileUpdate(string $table, array $data, array $where): CompiledQuery
    {
        $this->schema->requireTable($table);
        if ($data === []) {
            throw new QueryException('update requires at least one column', '', []);
        }
        if ($where === []) {
            throw new QueryException('update requires a WHERE clause', '', []);
        }

        $sets = [];
        $bindings = [];
        foreach ($data as $column => $value) {
            $this->schema->requireColumn($table, (string) $column);
            $sets[] = $column . ' = ?';
            $bindings[] = $value;
        }

        [$whereSql, $whereBindings] = $this->compileWhereMap($table, $where);
        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $whereSql;

        return new CompiledQuery($sql, array_merge($bindings, $whereBindings));
    }

    /**
     * @param array<string, mixed> $where
     */
    public function compileDelete(string $table, array $where): CompiledQuery
    {
        $this->schema->requireTable($table);
        if ($where === []) {
            throw new QueryException('delete requires a WHERE clause', '', []);
        }
        [$whereSql, $whereBindings] = $this->compileWhereMap($table, $where);

        return new CompiledQuery('DELETE FROM ' . $table . ' WHERE ' . $whereSql, $whereBindings);
    }

    /**
     * @param array<string, mixed>|object $data
     * @param list<string> $updateColumns
     */
    public function compileUpsert(string $table, array|object $data, array $updateColumns): CompiledQuery
    {
        if ($updateColumns === []) {
            throw new QueryException('upsert requires update columns', '', []);
        }
        $insert = $this->compileInsert($table, $data);
        foreach ($updateColumns as $column) {
            $this->schema->requireColumn($table, $column);
        }
        $assignments = [];
        foreach ($updateColumns as $column) {
            $assignments[] = $column . ' = new.' . $column;
        }
        $sql = $insert->sql . ' AS new ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);

        return new CompiledQuery($sql, $insert->bindings);
    }

    public function compileSelect(): CompiledQuery
    {
        if ($this->from === '') {
            throw new QueryException('SELECT requires FROM', '', []);
        }

        $bindings = [];
        $sql = '';
        if ($this->ctes !== []) {
            $parts = [];
            foreach ($this->ctes as $name => $cte) {
                $parts[] = $name . ' AS (' . $cte['sql'] . ')';
                foreach ($cte['bindings'] as $binding) {
                    $bindings[] = $binding;
                }
            }
            $sql .= 'WITH ' . implode(', ', $parts) . ' ';
        }

        $select = $this->selectSql();
        $sql .= 'SELECT ' . $select . ' FROM ' . $this->from;

        if ($this->wheres !== []) {
            $clauses = [];
            foreach ($this->wheres as [$column, $operator, $value]) {
                if ($operator === 'IN' || $operator === 'NOT IN') {
                    if (!is_array($value) || $value === []) {
                        throw new QueryException($operator . ' requires a non-empty array', '', []);
                    }
                    $placeholders = implode(', ', array_fill(0, count($value), '?'));
                    $clauses[] = $column . ' ' . $operator . ' (' . $placeholders . ')';
                    foreach ($value as $item) {
                        $bindings[] = $item;
                    }
                    continue;
                }
                $clauses[] = $column . ' ' . $operator . ' ?';
                $bindings[] = $value;
            }
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        if ($this->orderBys !== []) {
            $orders = [];
            foreach ($this->orderBys as [$column, $direction]) {
                $orders[] = $column . ' ' . $direction->value;
            }
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ?';
            $bindings[] = $this->limit;
        }

        return new CompiledQuery($sql, $bindings);
    }

    private function selectSql(): string
    {
        $parts = $this->selectColumns;
        foreach ($this->windows as $window) {
            $parts[] = 'ROW_NUMBER() OVER (PARTITION BY ' . $window['partitionBy']
                . ' ORDER BY ' . $window['orderBy'] . ') AS ' . $window['alias'];
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $where
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileWhereMap(string $table, array $where): array
    {
        $clauses = [];
        $bindings = [];
        foreach ($where as $column => $value) {
            $this->schema->requireColumn($table, (string) $column);
            $clauses[] = $column . ' = ?';
            $bindings[] = $value;
        }

        return [implode(' AND ', $clauses), $bindings];
    }

    /**
     * @param array<string, mixed>|object $data
     * @return array<string, mixed>
     */
    private function normalizeRow(array|object $data): array
    {
        return is_object($data) ? get_object_vars($data) : $data;
    }

    private function assertSource(string $table): void
    {
        IdentifierValidator::requireValid($table);
        if (isset($this->ctes[$table])) {
            return;
        }
        $this->schema->requireTable($table);
    }

    private function assertColumn(string $column): void
    {
        if ($column === '*') {
            return;
        }
        IdentifierValidator::requireValid($column);
        if ($this->from === '' || isset($this->ctes[$this->from]) || !$this->schema->hasTable($this->from)) {
            return;
        }
        $this->schema->requireColumn($this->from, $column);
    }

    private function assertSelectedColumns(): void
    {
        foreach ($this->selectColumns as $column) {
            $this->assertColumn($column);
        }
    }

    private function assertRawSql(string $sql): void
    {
        $this->assertNoStackedQueries($sql);
        $cteNames = $this->extractCteNames($sql);
        foreach ($this->extractTables($sql) as $table) {
            if (in_array($table, $cteNames, true)) {
                continue;
            }
            $this->schema->requireTable($table);
        }
    }

    private function assertNoStackedQueries(string $sql): void
    {
        $stripped = $this->stripSqlLiterals($sql);
        if (str_contains($stripped, ';')) {
            throw new InvalidIdentifierException(
                'Stacked queries are not allowed in raw SQL.',
                ';',
                'raw SQL contains multiple statements',
            );
        }
    }

    /**
     * @return list<string>
     */
    private function extractCteNames(string $sql): array
    {
        $stripped = $this->stripSqlLiterals($sql);
        if (preg_match('/^\s*WITH\s+/i', $stripped) !== 1) {
            return [];
        }
        if (preg_match_all(
            '/(?:WITH(?:\s+RECURSIVE)?|,)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?\s+AS\s*\(/i',
            $stripped,
            $matches,
        ) === 0) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<string>
     */
    private function extractTables(string $sql): array
    {
        $stripped = $this->stripSqlLiterals($sql);
        if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $stripped, $matches) === 0) {
            return [];
        }

        $tables = [];
        foreach ($matches[1] as $table) {
            if (strtoupper($table) === 'SELECT') {
                continue;
            }
            IdentifierValidator::requireValid($table);
            $tables[] = $table;
        }

        return array_values(array_unique($tables));
    }

    private function stripSqlLiterals(string $sql): string
    {
        $withoutStrings = preg_replace("/('(?:''|[^'])*')|(\"(?:\\\\\"|[^\"])*\")/", ' ', $sql) ?? $sql;

        return preg_replace('/--.*$/m', ' ', $withoutStrings) ?? $withoutStrings;
    }

    private function isReadSql(string $sql): bool
    {
        return preg_match('/^\s*(SELECT|SHOW|EXPLAIN|DESCRIBE|DESC|WITH)\b/i', $sql) === 1;
    }

    private function requireExecutor(): QueryExecutor
    {
        if ($this->executor === null) {
            throw new ConfigurationException('QueryBuilder has no connection to execute SQL.', 0, null, $this->isDebug());
        }

        return $this->executor;
    }

    private function isDebug(): bool
    {
        return $this->connection?->isDebug() ?? false;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function fork(array $changes): self
    {
        $clone = clone $this;
        foreach ($changes as $property => $value) {
            $clone->{$property} = $value;
        }

        return $clone;
    }
}
