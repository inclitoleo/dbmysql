<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Integration;

use Inclitoleo\Mysql\Mapper\EntityMapper;
use Inclitoleo\Mysql\Query\QueryBuilder;
use Inclitoleo\Mysql\Tests\Fixtures\AccountDto;
use Inclitoleo\Mysql\Tests\Fixtures\AccountRepository;
use Inclitoleo\Mysql\Tests\Support\TestDatabase;

final class EntityMapperTest extends IntegrationTestCase
{
    public function testInsertAndFindByIdRoundtrip(): void
    {
        [$repo] = $this->repository();
        $id = (int) $repo->insert(new AccountDto(null, 'Ada Lovelace', 'ada@example.com'));
        $found = $repo->findById($id);
        $this->assertInstanceOf(AccountDto::class, $found);
        $this->assertSame('Ada Lovelace', $found->name);
        $this->assertSame('ada@example.com', $found->email);
        $this->assertNull($repo->findById(999999));
    }

    public function testUpdateFieldsPreservesOtherColumns(): void
    {
        [$repo] = $this->repository();
        $id = (int) $repo->insert(new AccountDto(null, 'Keep Name', 'keep@example.com'));
        $repo->updateFields($id, ['email' => 'novo@email.com']);
        $found = $repo->findById($id);
        $this->assertNotNull($found);
        $this->assertSame('Keep Name', $found->name);
        $this->assertSame('novo@email.com', $found->email);
    }

    public function testFindWithOrdersRunsExplicitRelationLoad(): void
    {
        [$repo] = $this->repository();
        $loaded = $repo->findWithOrders(1);
        $this->assertNotNull($loaded);
        $this->assertInstanceOf(AccountDto::class, $loaded['entity']);
        $this->assertNotEmpty($loaded['related']);
        $this->assertSame(1, (int) $loaded['related'][0]->account_id);
    }

    /**
     * @return array{0: AccountRepository, 1: EntityMapper}
     */
    private function repository(): array
    {
        $schema = TestDatabase::schema();
        $manager = TestDatabase::manager();
        $mapper = new EntityMapper(new QueryBuilder($schema, $manager));
        $mapper->register(AccountDto::class, 'account', [
            'id' => 'id',
            'name' => 'name',
            'email' => 'email',
        ], ['name', 'email']);

        return [new AccountRepository($manager, $schema, $mapper), $mapper];
    }
}
