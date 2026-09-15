<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Tests\Fixtures;

use Inclitoleo\Mysql\Mapper\Repository;

final class AccountRepository extends Repository
{
    protected function entityClass(): string
    {
        return AccountDto::class;
    }

    protected function table(): string
    {
        return 'account';
    }

    protected function idColumn(): string
    {
        return 'id';
    }

    /**
     * @return array{entity: object, related: list<object>}|null
     */
    public function findWithOrders(int|string $id): ?array
    {
        return $this->findWithRelation($id, 'orders', 'account_id');
    }
}
