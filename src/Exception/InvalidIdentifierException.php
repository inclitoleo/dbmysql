<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Exception;

use Throwable;

class InvalidIdentifierException extends MysqlException
{
    public function __construct(
        string $message,
        private readonly string $identifier = '',
        private readonly string $reason = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
