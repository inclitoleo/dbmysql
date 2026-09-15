<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Unit\Mapper;

use Inclitoleo\Mysql\Connection\ConnectionConfig;
use Inclitoleo\Mysql\Connection\ConnectionManager;
use Inclitoleo\Mysql\Exception\ConfigurationException;
use Inclitoleo\Mysql\Mapper\EntityMapper;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Security\SchemaRegistry;
use Inclitoleo\Mysql\Tests\Fixtures\AccountDto;
use Inclitoleo\Mysql\Tests\Fixtures\AccountRepository;
use Inclitoleo\Mysql\Tests\Support\FakeConnectionStrategy;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class EntityMapperTest extends TestCase
{
    public function testToRowIgnoresUnmappedProperties(): void
    {
        $mapper = $this->mapper();
        $dto = new AccountDto(null, 'Leo', 'leo@example.com', 'cache-value');
        $row = $mapper->toRow($dto, AccountDto::class);

        $this->assertSame(['name' => 'Leo', 'email' => 'leo@example.com'], $row);
        $this->assertArrayNotHasKey('internalCache', $row);
        $dtoReflection = new \ReflectionClass($dto);
        $this->assertFalse($dtoReflection->hasProperty('pdo'));
        $this->assertFalse($dtoReflection->hasMethod('query'));
    }

    public function testFromRowHydratesMappedFields(): void
    {
        $mapper = $this->mapper();
        $dto = $mapper->fromRow((object) ['id' => 3, 'name' => 'Ada', 'email' => 'ada@example.com'], AccountDto::class);

        $this->assertInstanceOf(AccountDto::class, $dto);
        $this->assertSame(3, $dto->id);
        $this->assertSame('Ada', $dto->name);
        $this->assertSame('ada@example.com', $dto->email);
    }

    public function testMissingRequiredFieldThrowsBeforeExecution(): void
    {
        $mapper = $this->mapper();
        $this->expectException(ConfigurationException::class);
        $mapper->insert(AccountDto::class, new AccountDto(null, '', 'a@b.c'));
    }

    public function testFindByIdUsesExactlyOneQuery(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([
            (object) ['id' => 1, 'name' => 'Seed', 'email' => 'seed@example.com'],
        ]);

        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
        $pdo->expects($this->once())->method('prepare')->willReturn($statement);

        $schema = $this->schema();
        $manager = new ConnectionManager(new ConnectionConfig('localhost'), new FakeConnectionStrategy($pdo));
        $mapper = new EntityMapper(new QueryBuilder($schema, $manager));
        $mapper->register(AccountDto::class, 'account', [
            'id' => 'id',
            'name' => 'name',
            'email' => 'email',
        ], ['name', 'email']);
        $repo = new AccountRepository($manager, $schema, $mapper);

        $dto = $repo->findById(1);
        $this->assertInstanceOf(AccountDto::class, $dto);
        $this->assertSame('Seed', $dto->name);
    }

    public function testFindByIdReturnsNullWithoutException(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([]);
        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
        $pdo->method('prepare')->willReturn($statement);

        $schema = $this->schema();
        $manager = new ConnectionManager(new ConnectionConfig('localhost'), new FakeConnectionStrategy($pdo));
        $mapper = new EntityMapper(new QueryBuilder($schema, $manager));
        $mapper->register(AccountDto::class, 'account', [
            'id' => 'id',
            'name' => 'name',
            'email' => 'email',
        ], ['name', 'email']);
        $repo = new AccountRepository($manager, $schema, $mapper);

        $this->assertNull($repo->findById(999999));
    }

    public function testInsertReturnsLastInsertId(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('rowCount')->willReturn(1);
        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->getMock();
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('lastInsertId')->willReturn('15');

        $schema = $this->schema();
        $manager = new ConnectionManager(new ConnectionConfig('localhost'), new FakeConnectionStrategy($pdo));
        $mapper = new EntityMapper(new QueryBuilder($schema, $manager));
        $mapper->register(AccountDto::class, 'account', [
            'id' => 'id',
            'name' => 'name',
            'email' => 'email',
        ], ['name', 'email']);

        $this->assertSame('15', $mapper->insert(AccountDto::class, new AccountDto(null, 'Leo', 'leo@example.com')));
    }

    public function testUpdateFieldsMapsDtoKeysToColumns(): void
    {
        $compiled = null;
        $schema = $this->schema();
        $builder = new QueryBuilder($schema);
        $update = $builder->compileUpdate('account', ['email' => 'novo@email.com'], ['id' => 1]);
        $this->assertSame('UPDATE account SET email = ? WHERE id = ?', $update->sql);
        $this->assertStringNotContainsString('name', $update->sql);
        $this->assertSame($compiled, null);
    }

    private function mapper(): EntityMapper
    {
        $mapper = new EntityMapper(new QueryBuilder($this->schema()));
        $mapper->register(AccountDto::class, 'account', [
            'id' => 'id',
            'name' => 'name',
            'email' => 'email',
        ], ['name', 'email']);

        return $mapper;
    }

    private function schema(): SchemaRegistry
    {
        $schema = new SchemaRegistry();
        $schema->register('account', ['id', 'name', 'email']);
        $schema->register('orders', ['id', 'account_id', 'total']);

        return $schema;
    }
}
