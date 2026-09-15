<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Exception;

use Throwable;

class QueryException extends MysqlException
{
    /**
     * @param list<mixed>|array<string, mixed> $bindings
     */
    public function __construct(
        string $message,
        private readonly string $sql = '',
        private readonly array $bindings = [],
        private readonly ?string $sqlState = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * @return list<mixed>|array<string, mixed>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function getSqlState(): ?string
    {
        return $this->sqlState;
    }
}
