<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Mapper;

use Inclitoleo\Mysql\Exception\ConfigurationException;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Security\IdentifierValidator;
use ReflectionClass;
use ReflectionException;

final class EntityMapper
{
    /**
     * @var array<class-string, array{table: string, fields: array<string, string>, required: list<string>}>
     */
    private array $maps = [];

    public function __construct(
        private readonly QueryBuilder $builder,
    ) {
    }

    /**
     * @param class-string $class
     * @param array<string, string> $fieldToColumn
     * @param list<string> $required
     */
    public function register(string $class, string $table, array $fieldToColumn, array $required = []): void
    {
        IdentifierValidator::requireValid($table);
        foreach ($fieldToColumn as $field => $column) {
            IdentifierValidator::requireValid((string) $field);
            IdentifierValidator::requireValid($column);
        }
        foreach ($required as $field) {
            if (!isset($fieldToColumn[$field])) {
                throw new ConfigurationException('Required field ' . $field . ' is not mapped for ' . $class);
            }
        }

        $this->maps[$class] = [
            'table' => $table,
            'fields' => $fieldToColumn,
            'required' => $required,
        ];
    }

    /**
     * @param class-string $class
     */
    public function insert(string $class, object $dto): int|string
    {
        $map = $this->mapFor($class);
        $row = $this->toRow($dto, $class);
        foreach ($map['required'] as $field) {
            $column = $map['fields'][$field];
            if (!array_key_exists($column, $row) || $row[$column] === null || $row[$column] === '') {
                throw new ConfigurationException('Missing required field ' . $field . ' (' . $column . ') for ' . $class);
            }
        }

        return $this->builder->insert($map['table'], $row);
    }

    /**
     * @param class-string $class
     * @return array<string, mixed>
     */
    public function toRow(object $dto, string $class): array
    {
        $map = $this->mapFor($class);
        $row = [];
        $reflection = new ReflectionClass($dto);
        foreach ($map['fields'] as $field => $column) {
            if (!$reflection->hasProperty($field)) {
                continue;
            }
            $value = $dto->{$field};
            if ($column === 'id' && $value === null) {
                continue;
            }
            $row[$column] = $value;
        }

        return $row;
    }

    /**
     * @param class-string $class
     */
    public function fromRow(object|array $row, string $class): object
    {
        $map = $this->mapFor($class);
        $data = is_array($row) ? $row : get_object_vars($row);
        $values = [];
        foreach ($map['fields'] as $field => $column) {
            $values[$field] = $data[$column] ?? $data[$field] ?? null;
        }

        return $this->hydrate($class, $values);
    }

    /**
     * @param class-string $class
     * @return array<string, string>
     */
    public function fieldMap(string $class): array
    {
        return $this->mapFor($class)['fields'];
    }

    /**
     * @param class-string $class
     */
    public function table(string $class): string
    {
        return $this->mapFor($class)['table'];
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    public function columns(string $class): array
    {
        return array_values(array_unique(array_values($this->mapFor($class)['fields'])));
    }

    /**
     * @param class-string $class
     * @return array{table: string, fields: array<string, string>, required: list<string>}
     */
    private function mapFor(string $class): array
    {
        if (!isset($this->maps[$class])) {
            throw new ConfigurationException('No mapping registered for ' . $class);
        }

        return $this->maps[$class];
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $values
     */
    private function hydrate(string $class, array $values): object
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException $e) {
            throw new ConfigurationException('Unable to reflect entity ' . $class, 0, $e);
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            $instance = $reflection->newInstance();
            foreach ($values as $field => $value) {
                if ($reflection->hasProperty($field)) {
                    $property = $reflection->getProperty($field);
                    $property->setValue($instance, $value);
                }
            }

            return $instance;
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $values)) {
                $args[] = $values[$name];
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }
            $args[] = null;
        }

        return $reflection->newInstanceArgs($args);
    }
}
