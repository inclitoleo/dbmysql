<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Exception;

use Throwable;

class ConfigurationException extends MysqlException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, bool $debug = false)
    {
        parent::__construct($message, $code, $previous, ErrorCode::FAIL, $debug);
    }
}
