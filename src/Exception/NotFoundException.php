<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Exception;

use Throwable;

class NotFoundException extends MysqlException
{
    public function __construct(string $message = 'Record not found', bool $debug = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 404, $previous, ErrorCode::NOT_FOUND, $debug);
    }
}
