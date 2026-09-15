<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\Query;

enum SortDirection: string
{
    case ASC = 'ASC';
    case DESC = 'DESC';
}
