<?php

declare(strict_types=1);

return [
    'connection' => env('DBMYSQL_CONNECTION', env('DB_CONNECTION', 'mysql')),
    'debug' => (bool) env('DBMYSQL_DEBUG', false),
    'tables' => [
        // 'account' => ['id', 'name', 'email'],
        // 'orders' => ['id', 'account_id', 'total'],
    ],
];
