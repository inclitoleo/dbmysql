<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Query;

final class CompiledQuery
{
    /**
     * @param list<mixed> $bindings
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings = [],
    ) {
    }
}
