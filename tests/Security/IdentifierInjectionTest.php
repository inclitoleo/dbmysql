<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Security;

use Inclitoleo\Mysql\Exception\InvalidIdentifierException;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Security\IdentifierValidator;
use Inclitoleo\Mysql\Security\SchemaRegistry;
use PHPUnit\Framework\TestCase;

final class IdentifierInjectionTest extends TestCase
{
    /**
     * @dataProvider tablePayloads
     */
    public function testTablePayloadsAreRejected(string $payload): void
    {
        $builder = $this->builder();
        $this->expectException(InvalidIdentifierException::class);
        $builder->from($payload);
    }

    /**
     * @dataProvider tablePayloads
     */
    public function testDeleteTablePayloadsAreRejected(string $payload): void
    {
        $builder = $this->builder();
        $this->expectException(InvalidIdentifierException::class);
        $builder->compileDelete($payload, ['id' => 1]);
    }

    /**
     * @dataProvider columnPayloads
     */
    public function testColumnPayloadsAreRejected(string $payload): void
    {
        $builder = $this->builder();
        $this->expectException(InvalidIdentifierException::class);
        $builder->from('account')->select([$payload]);
    }

    public function testSecondOrderSubqueryAsColumnIsRejected(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->builder()->from('account')->select(['(SELECT password FROM users LIMIT 1)']);
    }

    public function testStackedQueryInRawSqlIsRejected(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->builder()->raw("SELECT * FROM account; DROP TABLE account", []);
    }

    public function testValidatorRejectsUnionInIdentifier(): void
    {
        $this->assertFalse(IdentifierValidator::isValid('account UNION SELECT'));
    }

    /**
     * @return list<list<string>>
     */
    public static function tablePayloads(): array
    {
        return [
            ['account; DROP TABLE account'],
            ['account UNION SELECT'],
            ["account'; DROP TABLE account;--"],
            ['account name'],
        ];
    }

    /**
     * @return list<list<string>>
     */
    public static function columnPayloads(): array
    {
        return [
            ['id; DROP TABLE account'],
            ['(SELECT password FROM users LIMIT 1)'],
            ["name' OR 1=1 --"],
        ];
    }

    private function builder(): QueryBuilder
    {
        $schema = new SchemaRegistry();
        $schema->register('account', ['id', 'name', 'email']);

        return new QueryBuilder($schema);
    }
}
