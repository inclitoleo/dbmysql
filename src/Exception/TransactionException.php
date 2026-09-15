<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Exception;

use Throwable;

class TransactionException extends MysqlException
{
    public function __construct(
        string $message,
        private readonly ?string $sqlState = null,
        int $code = 0,
        ?Throwable $previous = null,
        bool $debug = false,
    ) {
        $publicCode = $previous instanceof MysqlException
            ? $previous->publicCode()
            : ErrorCode::fromSqlState($sqlState, $code);

        parent::__construct($message, $code, $previous, $publicCode, $debug);
    }

    public function getSqlState(): ?string
    {
        return $this->sqlState;
    }
}
