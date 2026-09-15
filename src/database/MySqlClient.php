<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\database;

use Inclitoleo\Mysql\Connection\ConnectionManager;
use Inclitoleo\Mysql\Exception\MysqlException;
use Inclitoleo\Mysql\Exception\InvalidIdentifierException;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Query\SortDirection;
use Inclitoleo\Mysql\Security\IdentifierValidator;
use Inclitoleo\Mysql\Security\SchemaRegistry;
use PDO;
use Throwable;

/**
 * Compatibility facade over the v3 layer. Prefer ConnectionManager + QueryBuilder.
 *
 * @deprecated Use Inclitoleo\Mysql\Connection\ConnectionManager and Inclitoleo\Mysql\Query\QueryBuilder instead.
 * @name MySqlClient
 * @author LeoCosta (Inclitoleo) <inclitoleo@yandex.com>
 * @copyright Copyright (c) 2022
 * @created 2011-02-15 22:04
 * @revision 2026-09-14
 * @file MySqlClient.php
 * @version v3.0.0
 */
class MySqlClient extends DataBaseConnection
{
    /**
     * key capsule
     * @var array
     */
    protected $encapsulateKey = ['`', '`'];

    /**
     * @var PDO|null
     */
    protected $driver;

    private ?ConnectionManager $manager = null;

    private SchemaRegistry $schema;

    public function __construct()
    {
        $this->schema = new SchemaRegistry();
        try {
            $this->manager = new ConnectionManager($this->connectionConfigFromConstants());
            $this->driver = $this->manager->pdo();
        } catch (MysqlException $e) {
            $this->fail($e, true);
        }
    }

    /**
     * Insert data into the database
     *
     * @param string $table - Get the name of the table.
     * @param object $objValues - Receives the object with the values to be inserted.
     * @return integer|false last value inserted
     */
    public function insert(string $table, object $objValues): int|false
    {
        try {
            $this->registerFromObject($table, $objValues);
            $id = $this->builder()->insert($table, $objValues);

            return (int) $id;
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    /**
     * Returns an element inside the object
     *
     * @param string $table - Name of table in database
     * @param string $field - Database field to be compared default FALSE
     * @param int|float|string $value - Default search element FALSE
     * @return object|false Returns only one row within an object.
     */
    public function select(string $table, string $field, $value)
    {
        try {
            $columns = ($field !== '') ? [$field] : [];
            $this->allowTable($table, $columns);
            $query = $this->builder()->from($table);
            if ($field !== '' && $value !== false && $value !== null && $value !== '') {
                $query = $query->where($field, '=', $value);
            }
            $row = $query->first();

            return $row === null ? false : $row;
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    /**
     * Returns multiple elements within the object
     *
     * @param string $table - Name of table in database
     * @param array|bool $sort - Array for sort, array('field','type') type:(ASC or DESC)
     * @return array|false Returns only one row within an object.
     */
    public function select_s(string $table, $sort = false): array|false
    {
        try {
            $columns = is_array($sort) && isset($sort[0]) ? [(string) $sort[0]] : [];
            $this->allowTable($table, $columns);
            $query = $this->builder()->from($table);
            if (is_array($sort) && isset($sort[0], $sort[1])) {
                $query = $query->orderBy((string) $sort[0], SortDirection::from(strtoupper((string) $sort[1])));
            }

            return $query->get();
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    /**
     * Returns one or several elements within the object (via entire query)
     *
     * @param string $sqlSelect - SQL Statement
     * @param string $type - Whether to return one or all lines [U = Unique / A = All] (Default -> U)
     * @return object|array|false Array of returned data
     */
    public function select_all(string $sqlSelect, string $type = 'U'): object|array|false
    {
        try {
            $this->registerIdentifiersFromSql($sqlSelect);
            $rows = $this->builder()->raw($sqlSelect, []);
            if (!is_array($rows)) {
                return false;
            }
            if (strtoupper($type) === 'A') {
                return $rows;
            }

            return $rows[0] ?? false;
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    /**
     * Updates data in the database of a given element
     *
     * @param string $table - Database table
     * @param object $objValues Object with values to update
     * @param string $field - Field used as criteria
     * @param string $value - Value used as criterion
     * @return boolean true on success or false otherwise
     */
    public function update(string $table, object $objValues, string $field, string $value): bool
    {
        try {
            $this->registerFromObject($table, $objValues);
            $this->allowTable($table, [$field]);
            $this->builder()->update($table, get_object_vars($objValues), [$field => $value]);

            return true;
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    /**
     * Removes data from the database of a given element
     *
     * @param string $table - Database table
     * @param string $field - Field used as criteria
     * @param int|float|string $value - Value used as criteria
     * @return boolean true on success or false otherwise
     */
    public function delete(string $table, string $field, $value): bool
    {
        try {
            $this->allowTable($table, [$field]);
            $this->builder()->delete($table, [$field => $value]);

            return true;
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    /**
     * Initiate an SQL transaction in case of error perform rollback.
     * SELECT statements are executed without wrapping a long transaction.
     *
     * @param string $sql
     * @return array|false
     */
    public function execute(string $sql): array|false
    {
        try {
            $this->registerIdentifiersFromSql($sql);
            if (preg_match('/^\s*(SELECT|SHOW|EXPLAIN|DESCRIBE|DESC|WITH)\b/i', $sql) === 1) {
                $rows = $this->builder()->raw($sql, []);

                return is_array($rows) ? $rows : [];
            }

            $this->manager()->transaction(function () use ($sql): void {
                $this->builder()->raw($sql, []);
            });

            return [];
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    /**
     * @return array
     */
    public function getEncapsulateKey(): array
    {
        return $this->encapsulateKey;
    }

    /**
     * @param array $encapsulateKey
     * @return void
     */
    public function setEncapsulateKey(array $encapsulateKey)
    {
        $this->encapsulateKey = $encapsulateKey;
    }

    /**
     * Protected: Doubles the single quotes (') in the input string
     *
     * @param string $string - String to take effect
     * @return string String change
     */
    protected function escapeString(string $string): string
    {
        return preg_replace("/'/is", "''", $string) ?? $string;
    }

    public function manager(): ConnectionManager
    {
        if ($this->manager === null) {
            throw new MysqlException('MySqlClient is not connected.');
        }

        return $this->manager;
    }

    public function schema(): SchemaRegistry
    {
        return $this->schema;
    }

    private function builder(): QueryBuilder
    {
        return new QueryBuilder($this->schema, $this->manager());
    }

    /**
     * @param list<string> $columns
     */
    private function allowTable(string $table, array $columns = []): void
    {
        $validColumns = [];
        foreach ($columns as $column) {
            if ($column !== '' && IdentifierValidator::isValid($column)) {
                $validColumns[] = $column;
            } elseif ($column !== '') {
                IdentifierValidator::requireValid($column);
            }
        }
        $this->schema->register($table, $validColumns);
    }

    private function registerFromObject(string $table, object $objValues): void
    {
        $columns = [];
        foreach ($objValues as $key => $_) {
            $columns[] = (string) $key;
        }
        $this->allowTable($table, $columns);
    }

    private function registerIdentifiersFromSql(string $sql): void
    {
        if (str_contains($this->stripSqlLiterals($sql), ';')) {
            throw new InvalidIdentifierException(
                'Stacked queries are not allowed.',
                ';',
                'raw SQL contains multiple statements',
            );
        }
        $stripped = $this->stripSqlLiterals($sql);
        if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $stripped, $matches) > 0) {
            foreach ($matches[1] as $table) {
                if (strtoupper($table) === 'SELECT') {
                    continue;
                }
                $this->allowTable($table);
            }
        }
    }

    private function stripSqlLiterals(string $sql): string
    {
        $withoutStrings = preg_replace("/('(?:''|[^'])*')|(\"(?:\\\\\"|[^\"])*\")/", ' ', $sql) ?? $sql;

        return preg_replace('/--.*$/m', ' ', $withoutStrings) ?? $withoutStrings;
    }

    private function isStrict(): bool
    {
        return defined('INCLITOSTRICT') && INCLITOSTRICT === true;
    }

    /**
     * @return false
     */
    private function fail(Throwable $e, bool $connectionPrefix = false): mixed
    {
        if ($this->isStrict() || !$e instanceof MysqlException) {
            throw $e;
        }
        if ($connectionPrefix) {
            echo '##Verify configurations of the Database.##' . PHP_EOL;
        }
        echo $e->getMessage() . ' (Line file: ' . $e->getLine() . ') ' . $e->getFile() . PHP_EOL;

        return false;
    }
}
