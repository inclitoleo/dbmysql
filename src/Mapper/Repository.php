<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Mapper;

use Inclitoleo\Mysql\Connection\ConnectionManager;
use Inclitoleo\Mysql\Exception\NotFoundException;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Security\SchemaRegistry;
use Psr\Log\LoggerInterface;

abstract class Repository
{
    public function __construct(
        protected readonly ConnectionManager $connection,
        protected readonly SchemaRegistry $schema,
        protected readonly EntityMapper $mapper,
        protected readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return class-string
     */
    abstract protected function entityClass(): string;

    abstract protected function table(): string;

    abstract protected function idColumn(): string;

    public function findById(int|string $id): ?object
    {
        $row = $this->builder()
            ->from($this->table())
            ->select($this->selectColumns())
            ->where($this->idColumn(), '=', $id)
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->mapper->fromRow($row, $this->entityClass());
    }

    public function findByIdOrFail(int|string $id): object
    {
        $entity = $this->findById($id);
        if ($entity === null) {
            throw new NotFoundException('Record not found', $this->connection->isDebug());
        }

        return $entity;
    }

    public function insert(object $dto): int|string
    {
        return $this->mapper->insert($this->entityClass(), $dto);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updateFields(int|string $id, array $fields): int
    {
        $map = $this->mapper->fieldMap($this->entityClass());
        $columns = [];
        foreach ($fields as $key => $value) {
            $columns[$map[$key] ?? $key] = $value;
        }

        return $this->builder()->update($this->table(), $columns, [$this->idColumn() => $id]);
    }

    /**
     * Explicit eager-load helper. Runs at most two queries (entity + related rows).
     *
     * @return array{entity: object, related: list<object>}|null
     */
    protected function findWithRelation(int|string $id, string $relatedTable, string $foreignKey): ?array
    {
        $entity = $this->findById($id);
        if ($entity === null) {
            return null;
        }

        $related = $this->builder()
            ->from($relatedTable)
            ->where($foreignKey, '=', $id)
            ->get();

        return [
            'entity' => $entity,
            'related' => $related,
        ];
    }

    protected function builder(): QueryBuilder
    {
        return new QueryBuilder($this->schema, $this->connection, $this->logger ?? $this->connection->logger());
    }

    /**
     * @return list<string>
     */
    protected function selectColumns(): array
    {
        return $this->mapper->columns($this->entityClass());
    }
}
