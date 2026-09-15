<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Exception;

use Throwable;

class ConnectionException extends MysqlException
{
    public function __construct(
        string $message,
        private readonly ?string $sqlState = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getSqlState(): ?string
    {
        return $this->sqlState;
    }
}
