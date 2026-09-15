<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Unit\Exception;

use Inclitoleo\Mysql\Exception\ErrorCode;
use PHPUnit\Framework\TestCase;

final class ErrorCodeMappingTest extends TestCase
{
    /**
     * @dataProvider sqlStateMappings
     */
    public function testFromSqlState(?string $sqlState, int $driverCode, int $expected): void
    {
        $this->assertSame($expected, ErrorCode::fromSqlState($sqlState, $driverCode));
    }

    /**
     * @return array<string, array{0: ?string, 1: int, 2: int}>
     */
    public static function sqlStateMappings(): array
    {
        return [
            'access denied 28000/1045' => ['28000', 1045, ErrorCode::FORBIDDEN],
            'access denied 1044' => ['HY000', 1044, ErrorCode::FORBIDDEN],
            'access denied 1142' => ['42000', 1142, ErrorCode::FORBIDDEN],
            'access denied 1143' => ['42000', 1143, ErrorCode::FORBIDDEN],
            'access denied 1227' => ['42000', 1227, ErrorCode::FORBIDDEN],
            'access denied 1698' => ['28000', 1698, ErrorCode::FORBIDDEN],
            'sqlstate 28000 without driver' => ['28000', 0, ErrorCode::FORBIDDEN],
            'unknown table 42S02/1146' => ['42S02', 1146, ErrorCode::NOT_FOUND],
            'unknown table driver only' => ['HY000', 1146, ErrorCode::NOT_FOUND],
            'unknown column 42S22/1054' => ['42S22', 1054, ErrorCode::NOT_FOUND],
            'unknown column driver only' => ['42S22', 0, ErrorCode::NOT_FOUND],
            'duplicate key 23000/1062' => ['23000', 1062, ErrorCode::FAIL],
            'foreign key 23000/1452' => ['23000', 1452, ErrorCode::FAIL],
            'deadlock 40001/1213' => ['40001', 1213, ErrorCode::FAIL],
            'syntax 42000/1064' => ['42000', 1064, ErrorCode::FAIL],
            'generic HY000' => ['HY000', 0, ErrorCode::FAIL],
            'null sqlstate' => [null, 0, ErrorCode::FAIL],
        ];
    }

    public function testPublicJsonHelpers(): void
    {
        $this->assertSame('{"code":200}', ErrorCode::ok());
        $this->assertSame('{"code":403}', ErrorCode::json(ErrorCode::FORBIDDEN));
        $this->assertSame('{"code":404}', ErrorCode::json(ErrorCode::NOT_FOUND));
        $this->assertSame('{"code":500}', ErrorCode::json(ErrorCode::FAIL));
    }
}
