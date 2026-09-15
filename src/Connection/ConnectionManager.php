<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Connection;

use Inclitoleo\Mysql\Exception\ConfigurationException;
use Inclitoleo\Mysql\Exception\ConnectionException;
use Inclitoleo\Mysql\Exception\QueryException;
use Inclitoleo\Mysql\Exception\TransactionException;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;

final class ConnectionManager
{
    private ConnectionStrategy $strategy;

    /** @var array<string, ConnectionConfig> */
    private array $endpoints = [];

    /** @var array<string, PDO> */
    private array $connections = [];

    public function __construct(
        ConnectionConfig $config,
        ?ConnectionStrategy $strategy = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->strategy = $strategy ?? ($config->persistent
            ? new PersistentConnectionStrategy()
            : new SingleConnectionStrategy());
        $this->endpoints['primary'] = $config;
    }

    public function registerEndpoint(string $role, ConnectionConfig $config): void
    {
        if ($role !== 'primary' && $role !== 'replica') {
            throw new ConfigurationException('Endpoint role must be primary or replica.');
        }
        $this->endpoints[$role] = $config;
        unset($this->connections[$role]);
    }

    public function pdo(): PDO
    {
        return $this->connectionFor('write');
    }

    public function connection(): PDO
    {
        return $this->pdo();
    }

    public function connectionFor(string $operation): PDO
    {
        if ($operation !== 'read' && $operation !== 'write') {
            throw new ConfigurationException('Operation must be read or write.');
        }

        $role = ($operation === 'read' && isset($this->endpoints['replica'])) ? 'replica' : 'primary';
        if (!isset($this->endpoints[$role])) {
            throw new ConfigurationException('No ' . $role . ' endpoint registered.');
        }

        if (!isset($this->connections[$role])) {
            try {
                $this->connections[$role] = $this->strategy->connect($this->endpoints[$role]);
            } catch (ConnectionException $e) {
                $this->logger?->error('MySQL connection failed', [
                    'sqlState' => $e->getSqlState(),
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        return $this->connections[$role];
    }

    /**
     * @template T
     * @param callable(ConnectionManager): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->connectionFor('write');
        $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $sqlState = $this->sqlStateFrom($e);
            $this->logger?->error('MySQL transaction failed', [
                'sqlState' => $sqlState,
                'error' => $e->getMessage(),
            ]);

            $driverCode = $e instanceof PDOException
                ? PdoFactory::driverCodeFrom($e)
                : (int) $e->getCode();

            throw new TransactionException(
                'Transaction failed: ' . $e->getMessage(),
                $sqlState,
                $driverCode,
                $e,
                $this->isDebug(),
            );
        }
    }

    public function strategy(): ConnectionStrategy
    {
        return $this->strategy;
    }

    public function logger(): ?LoggerInterface
    {
        return $this->logger;
    }

    public function isDebug(): bool
    {
        return $this->endpoints['primary']->debug ?? false;
    }

    public function disconnect(): void
    {
        foreach ($this->connections as $pdo) {
            $this->strategy->disconnect($pdo);
        }
        $this->connections = [];
    }

    private function sqlStateFrom(Throwable $e): ?string
    {
        if ($e instanceof QueryException || $e instanceof ConnectionException || $e instanceof TransactionException) {
            return $e->getSqlState();
        }
        if ($e instanceof PDOException) {
            return PdoFactory::sqlStateFrom($e);
        }

        return null;
    }
}
