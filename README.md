# Doctrine DBAL for DuckDB

A [DuckDB](https://duckdb.org) database driver for Doctrine DBAL powered by the DuckDB PDO Driver.

Integrates DuckDB's analytical database engine into Doctrine, enabling fast analytical queries directly in your Symfony application.

<img width="500" height="273" alt="logo" src="logo.jpg?1" />

## Requirements

- PHP 8.2+
- Doctrine DBAL 4+
- Symfony 6+
- pdo_duckdb PHP extension

## Install and setup

Install and setup [pdo_duckdb](https://github.com/thomas-0816/pdo-duckdb-php) database driver with [PIE](https://github.com/php/pie):

```bash
pie install thomas-0816/pdo-duckdb-php
```

Install and setup Doctrine DBAL for DuckDB:

```bash
composer require thomas-0816/doctrine-dbal-duckdb
```

`pdo_duckdb` is a native DuckDB database driver for the PHP Data Objects (PDO) interface.\
As a native PHP extension, it is implemented in C/C++ and does not require PHP FFI or preloading.\
It is also thread safe and fully tested with FrankenPHP (PHP-ZTS).\
The release packages contain pre-compiled binaries for all supported platforms and DuckDB is directly included.\
DuckDB extensions work the same way as they do in DuckDB CLI.

## Configuration

Change `config/packages/doctrine.yaml`

```yaml
doctrine:
    dbal:
        url: 'duckdb://_/%kernel.project_dir%/var/db.duckdb'
        driver_schemes:
            duckdb: DuckDb\DBAL\Driver
        options:
            !php/const PDO::DUCKDB_ATTR_CONFIG:
                TimeZone: 'Europe/Berlin'
                # threads: 4 # max. number of threads
                # memory_limit: '4GB' # max. memory usage
                # access_mode: 'read_only' # open database file read-only
    orm:
        identity_generation_preferences:
            DuckDb\DBAL\Platforms\DuckDBPlatform: sequence
```

For testing or reading external files, use the special in-memory database `duckdb::memory:`

After changing doctrine.yaml, clear the cache:

```bash
rm -rf var/cache
```

Connection test:

```bash
php bin/console dbal:run-sql 'SELECT version(), current_database()'
```

## ORM Usage

Create a new entity `Product` with attributes `name` (string) and `price` (float):

```bash
echo -e "name\nstring\n\n\nprice\nfloat\n\n\n" | php bin/console make:entity Product

# php bin/console doctrine:migrations:diff
# php bin/console doctrine:migrations:migrate -vv
```

Create a new Product:

```php
$product = new Product();
$product->setName('foo');
$product->setPrice(12.34);

// use Doctrine\ORM\EntityManagerInterface from DI
$entityManager->persist($product);
$entityManager->flush();
```

Query, update and delete a Product:

```php
// use Doctrine\ORM\EntityManagerInterface from DI
$repository = $entityManager->getRepository(Product::class);
$product = $repository->findOneBy(['name' => 'foo']);
$product->setName('bar');
$entityManager->flush();

dump($repository->findOneBy(['name' => 'bar']));

# App\Entity\Product
#   -id: 1
#   -name: "bar"
#   -price: 12.34

$entityManager->remove($product);
$entityManager->flush();

dump($product = $repository->findOneBy(['name' => 'bar']));
# null
```

## Select with Doctrine Query Language

```php
// use Doctrine\ORM\EntityManagerInterface from DI
$query = $entityManager->createQuery("
    SELECT p
    FROM App\Entity\Product p
    WHERE p.name = :name
")->setParameter('name', 'foo');

dump($query->getResult());

# array
#   App\Entity\Product
#     -id: 1
#     -name: "foo"
#     -price: 12.34
```

## Select with Doctrine Query Builder

```php
// use Doctrine\ORM\EntityManagerInterface from DI
$query = $entityManager->createQueryBuilder()
    ->select('p')
    ->from(Product::class, 'p')
    ->where('p.name = :name')
    ->setParameter('name', 'foo')
    ->getQuery();
dump($query->execute());

# array
#   App\Entity\Product
#     -id: 1
#     -name: "foo"
#     -price: 12.34
```

## Select with SQL Query Builder

```php
// use Doctrine\ORM\EntityManagerInterface from DI
$result = $entityManager->getConnection()->createQueryBuilder()
    ->select('*')
    ->from("product") // or multiple files using /tmp/*.csv
    ->fetchAllAssociative();
dump($result);


```

## Select with SQL

```php
$sql = '
    SELECT *
    FROM product
    WHERE name = :name
';
// use Doctrine\ORM\EntityManagerInterface from DI
$result = $entityManager->getConnection()->executeQuery($sql, ['name' => 'foo']);
dump($result->fetchAllAssociative());

# array
#   array
#     "id" => 1
#     "name" => "foo"
#     "price" => 12.34
```

## Schema Builder

```php
// up
// use Doctrine\ORM\EntityManagerInterface from DI
$schema = $entityManager->getConnection()->createSchemaManager();

$sequence = $schema->introspectSchema()->createSequence('events_id_seq');
$schema->createSequence($sequence);

$table = $schema->introspectSchema()->createTable('events');
$table->addColumn('id', Types::INTEGER, ['default' => "nextval('events_id_seq')"]);
$table->addColumn('category', Types::STRING);
$table->addColumn('amount', Types::DECIMAL, ['precision' => 12, 'scale' => 2]);
$table->addColumn('tags', Types::JSON, ['notnull' => false]);
$table->setPrimaryKey(['id']);
$schema->createTable($table);

// php bin/console doctrine:migrations:migrate -vv --dry-run
// php bin/console doctrine:migrations:migrate -vv

// down
// use Doctrine\ORM\EntityManagerInterface from DI
$schema = $entityManager->getConnection()->createSchemaManager();
$schema->dropSequence('events_id_seq');
$schema->dropTable('events');
```

## Insert with SQL Query Builder

```php
// use Doctrine\ORM\EntityManagerInterface from DI
$entityManager->getConnection()->createQueryBuilder()
    ->insert('events')
    ->values(['category' => '?', 'amount' => '?', 'tags' => '?'])
    ->setParameters(['conference', 42.21, ['Hello', 'DuckDB']])
    ->executeStatement();
```

## Doctrine Entities

```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?string $name = null;

    #[ORM\Column]
    private ?float $price = null;
}
```

## Read CSV files with SQL Query Builder

```php
$list = [
    ['aaa', 'bbb', 'ccc'],
    ['123', '456', '789'],
    ['ddd', 'eee', 'fff'],
];
$fp = fopen('/tmp/test.csv', 'w');
foreach ($list as $fields) {
    fputcsv($fp, $fields, ',', '"', "");
}
fclose($fp);

// use Doctrine\ORM\EntityManagerInterface from DI
$result = $entityManager->getConnection()->createQueryBuilder()
    ->select('*')
    ->from("'/tmp/test.csv'") // or multiple files using /tmp/*.csv
    ->fetchAllAssociative();
dump($result);

# array
#   array
#     "aaa" => "123"
#     "bbb" => "456"
#     "ccc" => "789"
#   array
#     "aaa" => "ddd"
#     "bbb" => "eee"
#     "ccc" => "fff"
```

## Read CSV files with Doctrine ORM

```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
#[ORM\Table(name: "'/tmp/test.csv'")]
readonly class TestCsv
{
    #[ORM\Id]
    #[ORM\Column(name: 'row_number() over ()')] # emulate unique id
    public int $id;

    #[ORM\Column()]
    public ?string $aaa;

    #[ORM\Column()]
    public ?string $bbb;

    #[ORM\Column()]
    public ?string $ccc;
}
```

```php
$list = [
    ['aaa', 'bbb', 'ccc'],
    ['123', '456', '789'],
    ['ddd', 'eee', 'fff'],
];
$fp = fopen('/tmp/test.csv', 'w');
foreach ($list as $fields) {
    fputcsv($fp, $fields, ',', '"', "");
}
fclose($fp);

// use Doctrine\ORM\EntityManagerInterface from DI
$repository = $entityManager->getRepository(TestCsv::class);
dump($repository->findAll());

# array
#   App\Entity\TestCsv
#     +id: 1
#     +aaa: "123"
#     +bbb: "456"
#     +ccc: "789"
#   App\Entity\TestCsv
#     +id: 2
#     +aaa: "ddd"
#     +bbb: "eee"
#     +ccc: "fff"
```

## CSV data import with SQL Query Builder

```php
$list = [
    ['aaa', 'bbb'],
    ['123', '456'],
    ['aaa', 'bbb']
];
$fp = fopen('/tmp/test.csv', 'w');
foreach ($list as $fields) {
    fputcsv($fp, $fields, ',', '"', "");
}
fclose($fp);

// use Doctrine\ORM\EntityManagerInterface from DI
$conn = $entityManager->getConnection();
$conn->executeStatement("CREATE TABLE test_csv AS SELECT * FROM '/tmp/test.csv'"); // schema + data import
$conn->executeStatement("INSERT INTO test_csv SELECT * FROM '/tmp/test.csv'"); // only import data
dump($conn->executeQuery('SHOW test_csv')->fetchAllAssociative());

# array
#   array
#     "column_name" => "aaa"
#     "column_type" => "VARCHAR"
#     "null" => "YES"
#   array
#     "column_name" => "bbb"
#     "column_type" => "VARCHAR"
#     "null" => "YES"
```

## Read JSON files with SQL Query Builder

```php
file_put_contents('/tmp/logs.json', json_encode(['log' => 'log text']) . PHP_EOL, FILE_APPEND);
file_put_contents('/tmp/logs.json', json_encode(['log' => 'log text 2']) . PHP_EOL, FILE_APPEND);

// use Doctrine\ORM\EntityManagerInterface from DI
$result = $entityManager->getConnection()->createQueryBuilder()
    ->select('log')
    ->from("'/tmp/logs.json'") // or multiple files using '/tmp/*.json'
    ->fetchAllAssociative();
dump($result);

# array
#   array
#     "log" => "log text"
#   array
#     "log" => "log text 2"

// Convert JSON file to Parquet file
$sql = "COPY (SELECT * FROM '/tmp/logs.json') TO '/tmp/logs.parquet'";
$entityManager->getConnection()->executeStatement($sql);

$result = $entityManager->getConnection()->createQueryBuilder()
    ->select('log')
    ->from("'/tmp/logs.parquet'")
    ->fetchAllAssociative();
dump($result);

# array
#   array
#     "log" => "log text"
#   array
#     "log" => "log text 2"
```

## Read JSON files with Doctrine ORM

```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
#[ORM\Table(name: "'/tmp/logs.json'")]
readonly class TestJson
{
    #[ORM\Id]
    #[ORM\Column(name: 'row_number() over ()')] # emulate unique id
    public int $id;

    #[ORM\Column()]
    public ?string $log;
}

file_put_contents('/tmp/logs.json', json_encode(['log' => 'log text']) . PHP_EOL, FILE_APPEND);
file_put_contents('/tmp/logs.json', json_encode(['log' => 'log text 2']) . PHP_EOL, FILE_APPEND);

// use Doctrine\ORM\EntityManagerInterface from DI
$repository = $entityManager->getRepository(TestJson::class);
dump($repository->findAll());

# array
#   App\Entity\TestJson
#     +id: 1
#     +log: "log text"
#   App\Entity\TestJson
#     +id: 2
#     +log: "log text 2"
```

## Read Parquet files with Doctrine ORM

```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
#[ORM\Table(name: "'/tmp/logs.parquet'")]
readonly class LogsParquet
{
    #[ORM\Id]
    #[ORM\Column(name: 'row_number() over ()')] # emulate unique id
    public int $id;

    #[ORM\Column()]
    public ?string $log;
}

file_put_contents('/tmp/logs.json', json_encode(['log' => 'log text']) . PHP_EOL, FILE_APPEND);
file_put_contents('/tmp/logs.json', json_encode(['log' => 'log text 2']) . PHP_EOL, FILE_APPEND);

// Convert JSON file to Parquet file
// use Doctrine\ORM\EntityManagerInterface from DI
$conn = $entityManager->getConnection();
$conn->executeStatement("COPY (SELECT * FROM '/tmp/logs.json') TO '/tmp/logs.parquet'");

$repository = $entityManager->getRepository(LogsParquet::class);
dump($repository->findAll());

# array:2 [
#   App\Entity\LogsParquet
#     +id: 1
#     +log: "log text"
#   App\Entity\LogsParquet
#     +id: 2
#     +log: "log text 2"
```

__Apache Parquet__: very fast and efficient column based storage file format containing one table of data.\
Each column is split into several column groups. Depending on the query, the file can be read partially by certain columns groups.\
Different compression or dictionary algorithms can be applied to each column. Also supports encryption.

Note: You can read and save Parquet files on local file systems or directly on [S3 object storage](https://duckdb.org/docs/lts/core_extensions/httpfs/s3api).

## Read and write Parquet files with SQL Query Builder

```php
// use Doctrine\ORM\EntityManagerInterface from DI
$conn = $entityManager->getConnection();
$conn->executeStatement("CREATE TABLE table1 (id integer primary key, text varchar, data JSON)");

$conn->createQueryBuilder()->insert('table1')
    ->values(['id' => 1, 'text' => '?', 'data' => '?'])
    ->setParameters([1, 'Hello DuckDB', ['foo' => 'bar', 'baz' => 42]])
    ->executeStatement();

$conn->executeStatement("COPY (SELECT * FROM table1) TO '/tmp/table1.parquet'");

$rows = $conn->createQueryBuilder()
    ->select('*')
    ->from("'/tmp/table1.parquet'")
    ->fetchAllAssociative();
dump($rows);

# array
#   array
#     "id" => 1
#     "text" => "Hello DuckDB"
#     "data" => array
#       "foo" => "bar"
#       "baz" => 42
```

## Read public data using HTTPs, JSON, CSV and Parquet

Query weather data:

```php
// use Doctrine\ORM\EntityManagerInterface from DI
$result = $entityManager->getConnection()->createQueryBuilder()
    ->select('id', 'name.en')
    ->from("'https://bulk.meteostat.net/v2/stations/lite.json.gz'")
    ->where("name.en like '%Berlin%'")
    ->setMaxResults(2)
    ->fetchAllAssociative();
dump(json_encode($result));

# [{"id":"10381","en":"Berlin \/ Dahlem"},{"id":"10382","en":"Berlin \/ Tegel"}]

$result = $entityManager->getConnection()->createQueryBuilder()
    ->select('hour', 'temp')
    ->from("'https://data.meteostat.net/hourly/2026/10381.csv.gz'")
    ->where('year = 2026')->where('month = 7')->where('day = 25')->where('hour > 9')
    ->setMaxResults(3)
    ->fetchAllAssociative();
dump(json_encode($result));

# [{"hour":10,"temp":2.5},{"hour":11,"temp":2.9},{"hour":12,"temp":3.1}]
```

Download and query historical data from Deutsche Bahn:

```php
# wget https://huggingface.co/datasets/piebro/deutsche-bahn-data/resolve/main/monthly_processed_data/data-2026-07.parquet

// use Doctrine\ORM\EntityManagerInterface from DI
$rows = $entityManager->getConnection()->executeQuery("
    SELECT train_type, train_number, round(avg(delay_in_min)) as delay_avg, count(*) as count
    FROM 'data-2026-07.parquet' WHERE train_type = 'ICE'
    GROUP BY train_number, train_type
    ORDER BY delay_avg DESC
    LIMIT 10
")->fetchAllAssociative();
dump(array_map('json_encode', $rows));

# array
#   {"train_type":"ICE","train_number":"647","delay_avg":70,"count":35}
#   {"train_type":"ICE","train_number":"1541","delay_avg":66,"count":198}
#   {"train_type":"ICE","train_number":"2587","delay_avg":44,"count":72}
#   {"train_type":"ICE","train_number":"79152","delay_avg":44,"count":2}
#   {"train_type":"ICE","train_number":"2214","delay_avg":43,"count":159}
#   {"train_type":"ICE","train_number":"2311","delay_avg":42,"count":289}
#   {"train_type":"ICE","train_number":"953","delay_avg":42,"count":79}
#   {"train_type":"ICE","train_number":"526","delay_avg":41,"count":337}
#   {"train_type":"ICE","train_number":"859","delay_avg":41,"count":80}
#   {"train_type":"ICE","train_number":"2512","delay_avg":39,"count":28}

$rows = $entityManager->getConnection()->executeQuery("
    SELECT train_number, station_name, delay_in_min, hour(time) as hour, departure_is_canceled
    FROM 'data-2026-07.parquet'
    WHERE train_number = 647 AND time::date = '2026-07-11'
")->fetchAllAssociative();;
dump(array_map('json_encode', $rows));

# array
#   {"train_number":"647","station_name":"Dortmund Hbf","delay_in_min":82,"hour":0,"departure_is_canceled":false}
#   {"train_number":"647","station_name":"Hamm (Westf) Hbf","delay_in_min":120,"hour":1,"departure_is_canceled":false}
#   {"train_number":"647","station_name":"Bielefeld Hbf","delay_in_min":120,"hour":1,"departure_is_canceled":false}
#   {"train_number":"647","station_name":"Minden (Westf)","delay_in_min":123,"hour":2,"departure_is_canceled":false}
#   {"train_number":"647","station_name":"Hannover Hbf","delay_in_min":138,"hour":2,"departure_is_canceled":false}
#   {"train_number":"647","station_name":"Wolfsburg Hbf","delay_in_min":135,"hour":3,"departure_is_canceled":false}
#   {"train_number":"647","station_name":"Berlin Hauptbahnhof","delay_in_min":121,"hour":4,"departure_is_canceled":true}
#   {"train_number":"647","station_name":"Berlin S\u00fcdkreuz","delay_in_min":120,"hour":4,"departure_is_canceled":false}
#   {"train_number":"647","station_name":"Berlin-Spandau","delay_in_min":146,"hour":4,"departure_is_canceled":false}
```

## Copy data from MariaDB to a Parquet file

Start a MariaDB container, create and fill "orders" table:

```bash
docker run --rm -it -p 3306:3306 -e MARIADB_ROOT_PASSWORD=secret -e MARIADB_DATABASE=testdb mariadb:12
mysql -h 127.0.0.1 -u root -psecret testdb -e "
    CREATE TABLE orders (id integer primary key, customer integer, amount decimal(12, 2), origin varchar(255));
    INSERT INTO orders VALUES (1, 42, 123.42, 'shop');
    INSERT INTO orders VALUES (2, 21, 12.21, 'offline');
"
```

Use DuckDB [MySQL extension](https://duckdb.org/docs/lts/core_extensions/mysql) to copy "orders" table from MariaDB to a Parquet file:

```php
// use Doctrine\ORM\EntityManagerInterface from DI
$entityManager->getConnection()->executeStatement("
    INSTALL mysql;
    ATTACH 'host=127.0.0.1 port=3306 user=root password=secret database=testdb' AS testdb (TYPE mysql);
    COPY (select * from testdb.orders) TO '/tmp/orders.parquet' (FORMAT parquet);
");

$rows = $entityManager->getConnection()->createQueryBuilder()
    ->select('*')
    ->from("'/tmp/orders.parquet'")
    ->fetchAllAssociative();
dump($rows);

# array
#   array
#     "id" => 1
#     "customer" => 42
#     "amount" => 123.42
#     "origin" => "shop"
#   array
#     "id" => 2
#     "customer" => 21
#     "amount" => 12.21
#     "origin" => "offline"
```

## Copy data from PostgreSQL to a Parquet file

Start PostgreSQL container, create and fill "orders" table:

```bash
docker run --rm -it -p 5432:5432 -e POSTGRES_PASSWORD=secret postgres:18
PGPASSWORD=secret psql -h 127.0.0.1 -U postgres -c "
    CREATE TABLE orders (id integer primary key, customer integer, amount decimal(12, 2), origin varchar(255));
    INSERT INTO orders VALUES (1, 42, 123.42, 'shop');
    INSERT INTO orders VALUES (2, 21, 12.21, 'offline');
"
```

Use DuckDB [PostgreSQL extension](https://duckdb.org/docs/lts/core_extensions/postgres) to copy "orders" table from PostgreSQL to a Parquet file:

```php
// use Doctrine\ORM\EntityManagerInterface from DI
$entityManager->getConnection()->executeStatement("
    INSTALL postgres;
    ATTACH 'host=127.0.0.1 port=5432 user=postgres password=secret' AS testdb (TYPE postgres);
    COPY (select * from testdb.orders) TO '/tmp/orders.parquet' (FORMAT parquet);
");
$rows = $entityManager->getConnection()->createQueryBuilder()
    ->select('*')
    ->from("'/tmp/orders.parquet'")
    ->fetchAllAssociative();
dump($rows);

# array
#   array
#     "id" => 1
#     "customer" => 42
#     "amount" => 123.42
#     "origin" => "shop"
#   array
#     "id" => 2
#     "customer" => 21
#     "amount" => 12.21
#     "origin" => "offline"
```

## Schema and Query Builder for special types

Special types can be defined by using `columndefinition`:

```php
use Doctrine\DBAL\Types\Types;

// use Doctrine\ORM\EntityManagerInterface from DI
$schema = $entityManager->getConnection()->createSchemaManager();
$sequence = $schema->introspectSchema()->createSequence('events_id_seq');
$schema->createSequence($sequence);

$table = $schema->introspectSchema()->createTable('events');
$table->addColumn('id', 'integer', ['default' => "nextval('events_id_seq')"]);
$table->addColumn('numbers', 'duckdb', ['columndefinition' => 'integer[]']);
$table->addColumn('categories', 'duckdb', ['columndefinition' => 'varchar[]']);
$table->addColumn('person', 'duckdb', ['columndefinition' => 'STRUCT(v VARCHAR, va VARCHAR[], d DECIMAL)']);
$table->setPrimaryKey(['id']);
$schema->createTable($table);

class Person {
    public function __construct(
        public string $v,
        public array $va,
        public float $d
    ) {}
}

$person = new Person('foo', ['bar', 'baz'], 12.34);

$entityManager->getConnection()->createQueryBuilder()->insert('events')
    ->values(['numbers' => '?', 'categories' => '?', 'person' => '?'])
    ->setParameters([[21, 42], ['cat1', 'cat2'], $person])
    ->executeStatement();

$result = $entityManager->getConnection()->createQueryBuilder()
    ->select('*')
    ->from('events')
    ->fetchAllAssociative();
dump($result);

# array
#   array
#     "id" => 1
#     "numbers" => array
#       0 => 21
#       1 => 42
#     "categories" => array
#       0 => "cat1"
#       1 => "cat2"
#     "person" => array
#       "v" => "foo"
#       "va" => array
#         0 => "bar"
#         1 => "baz"
#       "d" => 12.34
```

## Doctrine ORM for special types

Special types can be defined by using `columndefinition`:

```php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

class Person {
    public function __construct(
        public string $v,
        public array $va,
        public float $d
    ) {}
}

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\Column(type: 'duckdb', columnDefinition: 'integer[]')]
    public array $numbers;

    #[ORM\Column(type: 'duckdb', columnDefinition: 'varchar[]')]
    public array $categories;

    #[ORM\Column(type: 'duckdb', columnDefinition: 'STRUCT(v VARCHAR, va VARCHAR[], d DECIMAL)')]
    public array|Person $person;

    /** @var Person[] */
    #[ORM\Column(type: 'duckdb', columnDefinition: 'STRUCT(v VARCHAR, va VARCHAR[], d DECIMAL)[]')]
    public array $persons;

    #[ORM\PostLoad]
    public function postLoad(): void
    {
        $this->person = new Person(...$this->person);
        $this->persons = array_map(fn($item) => is_array($item) ? new Person(...$item) : $item, $this->persons);
    }
}

$person = new Person('foo', ['bar', 'baz'], 12.34);

$event = new Event();
$event->numbers = [21, 42];
$event->categories = ['cat1', 'cat2'];
$event->person = $person;
$event->persons = [$person];

// use Doctrine\ORM\EntityManagerInterface from DI
$entityManager->persist($event);
$entityManager->flush();

$repository = $entityManager->getRepository(Event::class);
dump($repository->findAll());

# App\Entity\Event
#   +id: 1
#   +numbers: array
#     0 => 21
#     1 => 42
#   +categories: array
#     0 => "cat1"
#     1 => "cat2"
#   +person: App\Entity\Person
#     +v: "foo"
#     +va: array:2 [
#       0 => "bar"
#       1 => "baz"
#     +d: 12.34
#   +persons: array:1 [
#     0 => App\Entity\Person
#       +v: "foo"
#       +va: array:2 [
#         0 => "bar"
#         1 => "baz"
#       +d: 12.34
```

## Views

```php
// create or drop views
// use Doctrine\ORM\EntityManagerInterface from DI
$conn = $entityManager->getConnection();
$conn->executeStatement('CREATE VIEW view1 AS SELECT * FROM product');
$conn->executeStatement('DROP VIEW view1');
```

## Transactions

```php
$list = [
    ['aaa', 'bbb', 'ccc'],
    ['123', '456', '789'],
    ['ddd', 'eee', 'fff'],
];
$fp = fopen('/tmp/test.csv', 'w');
foreach ($list as $fields) {
    fputcsv($fp, $fields, ',', '"', "");
}
fclose($fp);

// use Doctrine\ORM\EntityManagerInterface from DI
$entityManager->getConnection()->transactional(function ($conn) {
    $conn->executeStatement("CREATE TABLE test_csv AS SELECT * FROM '/tmp/test.csv'");
    $conn->executeStatement("INSERT INTO test_csv SELECT * FROM '/tmp/test.csv'");
});
```

## Schema Dump

The package supports doctrine:schema:create command:

```bash
php bin/console doctrine:schema:create --dump-sql
```

## Query Debugging

change .env:

```ini
APP_DEBUG=true
```

install the monolog bundle and tail the log file:

```bash
composer require symfony/monolog-bundle
tail -f var/log/dev.log | grep -v "deprecation"
```

## Configuration without Doctrine ORM

Change `config/services.yaml`

```yaml
services:
    doctrine.dbal.connection:
        class: Doctrine\DBAL\Connection
        public: true
        factory: ['Doctrine\DBAL\DriverManager', 'getConnection']
        arguments:
            $params:
                path: '%kernel.project_dir%/var/db.duckdb' # or ':memory:'
                driverClass: 'DuckDb\DBAL\Driver'
                driverOptions:
                    !php/const PDO::DUCKDB_ATTR_CONFIG:
                        TimeZone: 'Europe/Berlin'
                        # threads: 4 # max. number of threads
                        # memory_limit: '4GB' # max. memory usage
                        # access_mode: 'read_only' # open database file read-only
    Doctrine\DBAL\Connection: '@doctrine.dbal.connection'
```

Connection test:

```php
// use Doctrine\DBAL\Connection from DI
$result = $connection->executeQuery('SELECT version(), current_database()');
dump($result->fetchAssociative());
```

## Connection setup without Symfony Framework Bundle

```php
use Doctrine\DBAL\DriverManager;
use DuckDb\DBAL\Driver;
use PDO;

$connection = DriverManager::getConnection([
    'driverClass' => Driver::class,
    'dbname' => ':memory:',
    'driverOptions' => [
        PDO::DUCKDB_ATTR_CONFIG => [
            'TimeZone' => 'Europe/Berlin',
            # 'threads' => 4, # max. number of threads
            # 'memory_limit' => '4GB', # max. memory usage
            # 'access_mode' => 'read_only', # open database file read-only
        ],
    ],
]);
$result = $connection->executeQuery('SELECT version(), current_database()');
dump($result->fetchAssociative());
```

## Performance

DuckDB is extremely fast when it comes to analytic queries.\
Here is an example with 10M rows, performing in __170ms on 4 threads with 128M ram__:

```sql
.timer on
/* generate 10M rows with random data */
COPY (
    SELECT i,
        (random()*1_000)::decimal(11,2) as d1,
        (random()*1_000)::int as i1,
        to_hex((random()*100000)::int) as h1,
        to_timestamp((i+1_0000_000) * random() * 100)::timestamp as created
    FROM generate_series(10_000_000) s(i)
) TO '/tmp/test.parquet' (format parquet, compression zstd);
/* Run Time (s): real 4.158 user 4.002094 sys 0.154674 */

SET threads = 4;
SET memory_limit = '128M';
SELECT count(*), sum(i), avg(d1), stddev(i1), avg(length(h1)), avg(date_diff('day', current_date, created))
FROM '/tmp/test.parquet';
/* Run Time (s): real 0.170 user 0.616465 sys 0.051658 */
```

## Security

Use SQL `SET variable = value;` or put the settings inside the PDO::DUCKDB_ATTR_CONFIG connection [options array](#Configuration):

```sql
# Disable extension loading
SET autoload_known_extensions = false;
SET autoinstall_known_extensions = false;
SET allow_community_extensions = false;

# Disable external file access, directory white listing
SET allowed_directories = ['/tmp'];
SET enable_external_access = false;

# Resource limits
SET threads = 4;
SET memory_limit = '4GB';
SET max_temp_directory_size = '4GB';

# Lock configuration
SET lock_configuration = true;
```

A complete list is available in the DuckDB documentation: [Securing DuckDB](https://duckdb.org/docs/lts/operations_manual/securing_duckdb/overview).

## Development

```bash
# testing
composer test
composer test_fix
./vendor/bin/phpunit --coverage-text
```

## Why DuckDB?

In-Process Architecture: Like SQLite, DuckDB embeds directly into host applications, eliminating the need for a separate server setup.

Extreme Analytical Speed: It uses columnar storage and vectorized (batch) processing, running analytics 10–100x faster than traditional row-oriented databases.

"Larger-than-Memory" Processing: DuckDB gracefully spills data to disk, allowing you to process massive datasets (e.g., 50GB+) on a machine with minimal RAM (e.g., 1GB).

File-Format Agnostic: It can query flat files (JSON, CSV, and Parquet) directly via SQL without needing to import or load the data into a database first.

No Infrastructure Cost: It brings data warehouse-level performance to your local laptop or local server.

DuckDB achieves blazing-fast analytical performance through its __embedded, serverless multi-core__ architecture combined with columnar storage and vectorized execution.
By executing queries directly within the host application, it eliminates serialization and network overhead, processing data in batches (vectors) rather
than row-by-row for unparalleled speed.

https://duckdb.org/why_duckdb

Key Performance Advantages:

Vectorized Query Execution: Unlike row-oriented engines, DuckDB processes data in cache-friendly batches (vectors). This allows modern hardware to operate on
entire arrays of data simultaneously, drastically reducing CPU cycles per query.

Columnar Storage: Data is stored by column rather than by row. For analytical queries that only require a few metrics,
DuckDB only reads the relevant columns from disk/memory, saving massive amounts of I/O.

Zero-Copy In-Process Engine: As an in-process database, DuckDB runs directly in the memory space of your application.

Advanced Query Optimizer: DuckDB features an advanced query optimizer that handles filter pushdowns, unnesting of subqueries, and dynamic runtime filters.
This ensures queries only scan necessary data and avoids full-table sorting when possible.

Direct File Querying: You can query large datasets in open formats like Parquet and CSV directly on disk or in cloud storage (like AWS S3) without needing to import or convert the data first.

## FAQ

> Do I need an extra server for DuckDB?

No. DuckDB runs completely embedded inside of PHP as an extension, just like SQLite.

> How much RAM and CPU do I need for DuckDB?

DuckDB normally runs good with 1-4 GB RAM and 2-4 CPU cores.

> How good is the compression with Parquet and zstd?

For logs you normally achieve compression rates of 50-100x.

> Who is maintaining DuckDB?

The DuckDB project is owned and maintained by the [DuckDB Foundation](https://duckdb.foundation), a non-profit organization from Amsterdam.

> Can I get commercial support for DuckDB?

Yes. Commercial support is available from [DuckLabs](https://ducklabs.com), a company based in Amsterdam.

> Can I get free support for DuckDB?

Yes. Free support is available on GitHub and Discord, see the [support policy](https://ducklabs.com/community_support_policy/) for details.\
You can meet the core team in-person on community events, meetup, conferences, etc.

> Is the Doctrine DBAL driver for DuckDB developed by the DuckDB project?

No. This is a third-party open-source community project.

> Is DuckDB fully open-source?

Yes. DuckDB and all components are fully open-source under the MIT license.\
There is no “enterprise version” of DuckDB.

## AI Disclosure

The code is written by AI, reviewed and tested without AI.

## License

MIT License
