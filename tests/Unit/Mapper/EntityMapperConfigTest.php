<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Unit\Mapper;

use Inclitoleo\Mysql\Exception\ConfigurationException;
use Inclitoleo\Mysql\Mapper\EntityMapper;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Security\SchemaRegistry;
use Inclitoleo\Mysql\Tests\Fixtures\AccountDto;
use PHPUnit\Framework\TestCase;

final class EntityMapperConfigTest extends TestCase
{
    public function testUnregisteredClassThrows(): void
    {
        $mapper = new EntityMapper(new QueryBuilder(new SchemaRegistry()));
        $this->expectException(ConfigurationException::class);
        $mapper->fromRow(['id' => 1], AccountDto::class);
    }

    public function testRequiredFieldMustBeMapped(): void
    {
        $schema = new SchemaRegistry();
        $schema->register('account', ['id', 'name', 'email']);
        $mapper = new EntityMapper(new QueryBuilder($schema));
        $this->expectException(ConfigurationException::class);
        $mapper->register(AccountDto::class, 'account', ['id' => 'id'], ['name']);
    }
}
