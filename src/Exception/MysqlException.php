<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Exception;

use RuntimeException;
use Throwable;

class MysqlException extends RuntimeException
{
    private readonly string $detail;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly int $publicCode = ErrorCode::FAIL,
        private readonly bool $debug = false,
    ) {
        $this->detail = $message;
        parent::__construct(
            $debug ? $message : ErrorCode::json($publicCode),
            $code,
            $previous,
        );
    }

    public function publicCode(): int
    {
        return $this->publicCode;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function getDetail(): string
    {
        return $this->detail;
    }

    public function toJson(): string
    {
        return ErrorCode::json($this->publicCode);
    }
}
