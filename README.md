#Version: v2.0.2022
# Libary MySQL
Library for connecting MySQL databases. Simple, fast and objective.

## Install dependency
```shell
composer require inclitoleo/dbmysql
```

## Connection data
```php
<?php
const INCLITOHOST = 'localhost:3306'; //Host access Database, If it is necessary to indicate port, use this format: HOST:PORT
const INCLITODBNAME = ''; //Name Database
const INCLITOUSER = ''; //User Connection
const INCLITOPWD = ''; //Password Database
const INCLITODRIVER = 'mysql'; //Driver Default
const INCLITOTYPECONN = false; //Persistent true or false, default false;

//Certificate SSL
const INCLITOBOOLCERT = false; //Default false
const INCLITOMYCA = ''; //File CA
const INCLITOMYCERT = ''; //File CERT
const INCLITOMYKEY =  ''; //File KEY
```
## Documentation
```php
<?php
require_once __DIR__.'/vendor/autoload.php';
use Inclitoleo\Mysql\database\MySqlClient;

$db = new MySqlClient();

/**
 * structure table of test
 *
 * CREATE TABLE account (
        id int not null auto_increment,
        name varchar(255) not null,
        email varchar(255) not null,
        primary key(id)
    );
 */

/**
 * Insert data into the database.
 * Create a variable and the objects must be the same as the table field name.
 * @param string $table - Get the name of the table.
 * @param object $objValues - Receives the object with the values to be inserted.
 * @return integer last value inserted
 *
 */
$insert = new stdClass();
$insert->name = 'Leonardo Costa';
$insert->email = 'inclitoleo@yandex.com';

$lastid = $db->insert('account',$insert);

echo 'This is ID: '.$lastid;

/**
 * Returns an element inside the object
 * @param string $table - Name of table in database
 * @param string $field - Database field to be compared default FALSE
 * @param int|float|string $value - Default search element FALSE
 * @return object Returns only one row within an object.
 */
$id = 1;
$select = $db->select('account','id',$id);
echo $select->name .' | '.$select->email;

echo $db->select('account','id',9)->name;
echo $db->select('account','id',9)->email;

/**
 * Returns multiple elements within the object
 *
 * @abstract
 * @param string $table - Name of table in database
 * @param array|bool $sort - Array for sort, array('field','type') type:(ASC or DESC)
 * @return array Returns only one row within an object.
 */
$selectall = $db->select_s('account'); // Return all
$selectsort = $db->select_s('account',array('name','ASC')); // Return all in order
var_dump($selectall);
echo '<br>############################<br>';
var_dump($selectsort);

/**
 * Returns one or several elements within the object (via entire query)
 * @param string $sqlSelect - SQL Statement
 * @param string $type - Whether to return one or all lines [U = Unique / A = All] (Default -> U)
 * @return array Array of returned data
 */
$selectone = $db->select_all("SELECT * FROM account WHERE name = 'Leo'"); //One register
echo $selectone->name;

$selecta = $db->select_all("SELECT * FROM account WHERE name = 'Leo' ", 'A'); //All register
var_dump($selecta);


/**
 * Updates data in the database of a given element
 * @param string $table - Database table
 * @param object $objValues Object with values to update
 * (
 *      "field_1->new_value_1"
 *      "field_2->new_value_2"
 * )
 * @param string $field - Field used as criteria
 * @param string $value - Value used as criterion
 * @return boolean true on success or false otherwise
 */
$id = 9;
$update = new stdClass();
$update->name = 'Kazan Name';
$update->email = 'linuxmanbr@yandex.com';

$db->update('account',$update,'id',$id);

/**
 * Removes data from the database of a given element
 * @param string $table - Database table
 * @param string $field - Field used as criteria
 * @param int|float|string $value - Value used as criteria
 * @return boolean true on success or false otherwise
 */
$id = 9;
$db->delete('account','id',$id);

/**
 * Initiate an SLQ transaction in case of error perform rollback
 * @param string $sql
 * @return array
 */
$exec = $db->execute('SELECT * FROM account');
$exec = $db->execute("INSERT INTO account (name,email) VALUES ('New Name','newmail@br.com')");
```
