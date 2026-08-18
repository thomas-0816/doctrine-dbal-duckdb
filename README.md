# Doctrine DBAL for DuckDB

A [DuckDB](https://duckdb.org) database driver for Doctrine DBAL powered by the DuckDB PDO Driver.

Integrates DuckDB's analytical database engine into Doctrine, enabling fast analytical queries directly in your Symfony application.

<img width="500" height="273" alt="logo" src="logo.jpg?1" />

## Requirements

- PHP 8.2+
- Doctrine DBAL 4+
- Symfony 7+
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

change `config/packages/doctrine.yaml`

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

For testing or reading external files, use the special in-memory database `duckdb::memory:`.

after changing doctrine.yaml, clear the cache:

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
# }

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

## Select with Query Builder

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

## work in progress ...

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
