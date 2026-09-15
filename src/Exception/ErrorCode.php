<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Exception;

final class ErrorCode
{
    public const OK = 200;
    public const FORBIDDEN = 403;
    public const NOT_FOUND = 404;
    public const FAIL = 500;

    public static function json(int $code): string
    {
        return '{"code":' . $code . '}';
    }

    public static function ok(): string
    {
        return self::json(self::OK);
    }

    public static function fromSqlState(?string $sqlState, int $driverCode = 0): int
    {
        $state = strtoupper((string) $sqlState);
        $forbiddenDriver = [1044, 1045, 1142, 1143, 1227, 1698];
        if (in_array($driverCode, $forbiddenDriver, true) || $state === '28000') {
            return self::FORBIDDEN;
        }
        if (in_array($state, ['42S02', '42S22'], true) || in_array($driverCode, [1146, 1054], true)) {
            return self::NOT_FOUND;
        }

        return self::FAIL;
    }
}
