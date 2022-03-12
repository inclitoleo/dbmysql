<?php

namespace Inclitoleo\Mysql\database;

/**
 * @file ModelDataBase.php
 * Library responsible for DB connection control and manipulation
 * @name DataBaseConnection
 * @author LeoCosta (Inclitoleo) <inclitoleo@yandex.com>
 * @copyright Copyright (c) 2022
 * @created 2011-02-15 22:04
 * @revision 2022-03-12 12:19
 * @version v2.0.2022
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

        $dataconn->driver = INCLITODRIVER;
        $dataconn->host = INCLITOHOST;
        $dataconn->port = INCLITOPORT;
        $dataconn->username = INCLITOUSER;
        $dataconn->password = INCLITOPWD;
        $dataconn->database = INCLITODBNAME;

        return $dataconn;
    }

}

