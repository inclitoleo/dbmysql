<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Query;

use Generator;
use Inclitoleo\Mysql\Connection\ConnectionManager;
use Inclitoleo\Mysql\Connection\PdoFactory;
use Inclitoleo\Mysql\Exception\QueryException;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;

final class QueryExecutor
{
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param list<mixed>|array<string, mixed> $bindings
     * @return list<object>
     */
    public function fetchAll(string $sql, array $bindings, string $operation = 'read'): array
    {
        $pdo = $this->connections->connectionFor($operation);
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($this->normalizeBindings($bindings));

            /** @var list<object> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_OBJ);

            return $rows;
        } catch (PDOException $e) {
            $this->throwQuery($sql, $bindings, $e);
        }
    }

    /**
     * @param list<mixed>|array<string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings, string $operation = 'write'): int
    {
        $pdo = $this->connections->connectionFor($operation);
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($this->normalizeBindings($bindings));

            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->throwQuery($sql, $bindings, $e);
        }
    }

    public function lastInsertId(string $operation = 'write'): string
    {
        return $this->connections->connectionFor($operation)->lastInsertId();
    }

    /**
     * @param list<mixed>|array<string, mixed> $bindings
     * @return Generator<int, object>
     */
    public function cursor(string $sql, array $bindings): Generator
    {
        $pdo = $this->connections->connectionFor('read');
        $bufferedAttr = defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY') ? PDO::MYSQL_ATTR_USE_BUFFERED_QUERY : null;
        if ($bufferedAttr !== null) {
            $pdo->setAttribute($bufferedAttr, false);
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($this->normalizeBindings($bindings));
            while (($row = $stmt->fetch(PDO::FETCH_OBJ)) !== false) {
                yield $row;
            }
            $stmt->closeCursor();
        } catch (PDOException $e) {
            $this->throwQuery($sql, $bindings, $e);
        } finally {
            if ($bufferedAttr !== null) {
                $pdo->setAttribute($bufferedAttr, true);
            }
        }
    }

    /**
     * @param list<mixed>|array<string, mixed> $bindings
     * @return never
     */
    private function throwQuery(string $sql, array $bindings, PDOException $e): never
    {
        $sqlState = PdoFactory::sqlStateFrom($e);
        $this->logger?->error('MySQL query failed', [
            'sql' => $sql,
            'sqlState' => $sqlState,
            'bindings' => $bindings,
        ]);

        throw new QueryException(
            'Query failed: ' . $e->getMessage(),
            $sql,
            $bindings,
            $sqlState,
            (int) $e->getCode(),
            $e,
            $this->connections->isDebug(),
        );
    }

    /**
     * @param list<mixed>|array<string, mixed> $bindings
     * @return list<mixed>|array<string, mixed>
     */
    private function normalizeBindings(array $bindings): array
    {
        if ($bindings === []) {
            return [];
        }

        return array_is_list($bindings) ? array_values($bindings) : $bindings;
    }
}
