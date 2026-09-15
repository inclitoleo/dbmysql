<?php

declare(strict_types=1);

namespace Inclitoleo\Mysql\database;

use Inclitoleo\Mysql\Connection\ConnectionConfig;
use Inclitoleo\Mysql\Connection\SslConfig;

/**
 * @file ModelDataBase.php
 * Library responsible for DB connection control and manipulation
 * @name DataBaseConnection
 * @author LeoCosta (Inclitoleo) <inclitoleo@yandex.com>
 * @copyright Copyright (c) 2022
 * @created 2011-02-15 22:04
 * @revision 2026-09-14
 * @version v3.0.0
 */
class DataBaseConnection
{
    /**
     * Returns the database connection and its methods
     * @return object
     */
    protected function Conn()
    {
        $dataconn = new \stdClass();

        $host = defined('INCLITOHOST') ? (string) INCLITOHOST : '';
        $port = defined('INCLITOPORT') ? (int) INCLITOPORT : 3306;
        if (preg_match('/^(.+):(\d+)$/', $host, $matches) === 1) {
            $host = $matches[1];
            if (!defined('INCLITOPORT')) {
                $port = (int) $matches[2];
            }
        }

        $dataconn->driver = defined('INCLITODRIVER') ? INCLITODRIVER : 'mysql';
        $dataconn->host = $host;
        $dataconn->port = $port;
        $dataconn->username = defined('INCLITOUSER') ? INCLITOUSER : '';
        $dataconn->password = defined('INCLITOPWD') ? INCLITOPWD : '';
        $dataconn->database = defined('INCLITODBNAME') ? INCLITODBNAME : '';

        return $dataconn;
    }

    protected function connectionConfigFromConstants(): ConnectionConfig
    {
        $conn = $this->Conn();
        $sslEnabled = defined('INCLITOBOOLCERT') && INCLITOBOOLCERT === true;
        $ssl = null;
        if ($sslEnabled) {
            $ssl = new SslConfig(
                ca: defined('INCLITOMYCA') ? (string) INCLITOMYCA : '',
                cert: defined('INCLITOMYCERT') ? (string) INCLITOMYCERT : '',
                key: defined('INCLITOMYKEY') ? (string) INCLITOMYKEY : '',
            );
        }

        return new ConnectionConfig(
            host: (string) $conn->host,
            port: (int) $conn->port,
            database: (string) $conn->database,
            username: (string) $conn->username,
            password: (string) $conn->password,
            persistent: defined('INCLITOTYPECONN') ? (bool) INCLITOTYPECONN : false,
            sslEnabled: $sslEnabled,
            ssl: $ssl,
        );
    }
}
