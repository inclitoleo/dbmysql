<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Security;

use Inclitoleo\Mysql\Exception\InvalidIdentifierException;

final class SchemaRegistry
{
    /** @var array<string, list<string>> */
    private array $tables = [];

    /**
     * @param list<string> $columns
     */
    public function register(string $table, array $columns): void
    {
        IdentifierValidator::requireValid($table);
        foreach ($columns as $column) {
            IdentifierValidator::requireValid($column);
        }

        $existing = $this->tables[$table] ?? [];
        $merged = array_values(array_unique(array_merge($existing, array_values($columns))));
        $this->tables[$table] = $merged;
    }

    public function hasTable(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return $this->hasTable($table) && in_array($column, $this->tables[$table], true);
    }

    public function requireTable(string $table): void
    {
        IdentifierValidator::requireValid($table);
        if (!$this->hasTable($table)) {
            throw new InvalidIdentifierException(
                'Table is not registered: ' . $table,
                $table,
                'table is not in the schema whitelist',
            );
        }
    }

    public function requireColumn(string $table, string $column): void
    {
        $this->requireTable($table);
        IdentifierValidator::requireValid($column);
        if (!$this->hasColumn($table, $column)) {
            throw new InvalidIdentifierException(
                'Column is not registered: ' . $table . '.' . $column,
                $column,
                'column is not registered for table ' . $table,
            );
        }
    }

    /**
     * @return list<string>
     */
    public function columns(string $table): array
    {
        $this->requireTable($table);

        return $this->tables[$table];
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return array_keys($this->tables);
    }
}
