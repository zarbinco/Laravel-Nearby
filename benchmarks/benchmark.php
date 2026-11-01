<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Benchmarks;

use Composer\InstalledVersions;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Eloquent\Concerns\HasNearbyCoordinates;
use Zarbinco\Nearby\Eloquent\MySqlDistanceExpression;

require dirname(__DIR__).'/vendor/autoload.php';

final class BenchmarkLocation extends Model
{
    use HasNearbyCoordinates;

    public $timestamps = false;
}

final class DeterministicNumbers
{
    public function __construct(private int $state = 104729) {}

    public function next(): float
    {
        $this->state = ($this->state * 48271) % 2147483647;

        return ($this->state - 1) / 2147483646;
    }

    public function normal(): float
    {
        return sqrt(-2.0 * log(max($this->next(), 1.0e-15))) * cos(2.0 * M_PI * $this->next());
    }
}

function usage(): string
{
    return <<<'TEXT'
Usage: php benchmarks/benchmark.php --run [options]

Requires DB_HOST, DB_USERNAME and DB_DATABASE ending in _benchmark.
DB_PORT defaults to 3306; DB_PASSWORD can be empty. MySQL 8.4 is required.
Only a uniquely named benchmark table is created, indexed and dropped.

  --sizes=1000,10000,100000     Default: 1000. Maximum: 1000000.
  --distributions=uniform,clustered
  --indexes=none,latitude,longitude,latitude_longitude,longitude_latitude,application_first
  --operations=distance_all,radius_small,radius_medium,radius_large,nearest_unbounded,nearest_bounded,distance_band,application_filter
  --warmups=2                 Untimed warm-ups per query; default: 2.
  --repetitions=5             Measured samples per query; minimum: 3.
  --plans=json                json, tree, both or none.
  --analyze                   Also execute EXPLAIN ANALYZE for each query.
  --investigate-alias         Also compare ordering by the selected distance alias.
  --output=build/benchmark.json
  --help                      Show this help without connecting to MySQL.

Large datasets and EXPLAIN ANALYZE are opt-in. See benchmarks/README.md.
TEXT;
}

/** @return array<string, string|bool> */
function options(array $arguments): array
{
    $result = [];
    $flags = ['run', 'help', 'analyze', 'investigate-alias'];
    $values = ['sizes', 'distributions', 'indexes', 'operations', 'warmups', 'repetitions', 'plans', 'output'];

    foreach ($arguments as $argument) {
        $parts = explode('=', substr($argument, 2), 2);
        $name = $parts[0];

        if (!str_starts_with($argument, '--') || (!in_array($name, $flags, true) && !in_array($name, $values, true))) {
            throw new InvalidArgumentException('Unknown benchmark option: '.$argument);
        }

        if (in_array($name, $flags, true)) {
            if (count($parts) !== 1) {
                throw new InvalidArgumentException('--'.$name.' does not accept a value.');
            }

            $result[$name] = true;
        } else {
            if (!isset($parts[1]) || $parts[1] === '') {
                throw new InvalidArgumentException('Use --'.$name.'=value.');
            }

            $result[$name] = $parts[1];
        }
    }

    return $result;
}

function integerOption(string $value, int $minimum, int $maximum): int
{
    if (!ctype_digit($value) || (float) $value < $minimum || (float) $value > $maximum) {
        throw new InvalidArgumentException(sprintf('Expected an integer from %d to %d; received %s.', $minimum, $maximum, $value));
    }

    return (int) $value;
}

/** @return list<string> */
function choices(string $value, array $allowed): array
{
    $selected = array_values(array_unique(explode(',', $value)));

    if ($selected === [] || array_diff($selected, $allowed) !== []) {
        throw new InvalidArgumentException('Choose from: '.implode(', ', $allowed));
    }

    return $selected;
}

function quoteIdentifier(string $identifier): string
{
    if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $identifier) !== 1) {
        throw new InvalidArgumentException('Unsafe benchmark identifier.');
    }

    return '`'.$identifier.'`';
}

function writeReport(string $path, array $report): void
{
    if (file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false) {
        throw new RuntimeException('Unable to write the benchmark report.');
    }
}

function seed(PDO $pdo, string $table, int $size, string $distribution, Coordinate $origin): string
{
    $pdo->exec('TRUNCATE TABLE '.quoteIdentifier($table));
    $numbers = new DeterministicNumbers();
    $hash = hash_init('sha256');
    $batch = [];

    for ($id = 1; $id <= $size; $id++) {
        $clustered = $distribution === 'clustered' && $numbers->next() < 0.8;

        if ($clustered) {
            $latitude = $origin->latitude + 0.6 * $numbers->normal();
            $longitude = $origin->longitude + 0.6 * $numbers->normal() / cos(deg2rad($origin->latitude));
        } else {
            $latitude = rad2deg(asin(2.0 * $numbers->next() - 1.0));
            $longitude = 360.0 * $numbers->next() - 180.0;
        }

        $row = [$id, MySqlDistanceExpression::bindNumber($latitude), MySqlDistanceExpression::bindNumber($longitude),
            1 + (int) floor(20.0 * $numbers->next()), $numbers->next() < 0.75 ? 1 : 0];
        hash_update($hash, implode(',', $row)."\n");
        $batch[] = $row;

        if (count($batch) === 500 || $id === $size) {
            $sql = 'INSERT INTO '.quoteIdentifier($table).' (id, latitude, longitude, tenant_id, is_active) VALUES '
                .implode(', ', array_fill(0, count($batch), '(?, ?, ?, ?, ?)'));
            $pdo->prepare($sql)->execute(array_merge(...$batch));
            $batch = [];
        }
    }

    return hash_final($hash);
}

/** @return array<string, array<string, int|bool|string|null>> */
function operations(): array
{
    return [
        'distance_all' => ['distance' => true, 'radius' => null, 'minimum' => null, 'nearest' => false, 'application' => false],
        'radius_small' => ['distance' => false, 'radius' => 5000, 'minimum' => null, 'nearest' => false, 'application' => false],
        'radius_medium' => ['distance' => false, 'radius' => 100000, 'minimum' => null, 'nearest' => false, 'application' => false],
        'radius_large' => ['distance' => false, 'radius' => 10000000, 'minimum' => null, 'nearest' => false, 'application' => false],
        'nearest_unbounded' => ['distance' => true, 'radius' => null, 'minimum' => null, 'nearest' => true, 'application' => false],
        'nearest_bounded' => ['distance' => true, 'radius' => 250000, 'minimum' => null, 'nearest' => true, 'application' => false],
        'distance_band' => ['distance' => false, 'radius' => 100000, 'minimum' => 25000, 'nearest' => false, 'application' => false],
        'application_filter' => ['distance' => false, 'radius' => 100000, 'minimum' => null, 'nearest' => false, 'application' => true],
    ];
}

function buildQuery(BenchmarkLocation $model, Coordinate $origin, array $operation, string $variant): Builder
{
    $query = $model->newQuery()->select($model->qualifyColumn('id'));

    if ($operation['application']) {
        $query->where('tenant_id', 3)->where('is_active', true);
    }

    if ($variant === 'baseline') {
        $expression = new MySqlDistanceExpression($query, $origin, 'latitude', 'longitude');

        if ($operation['distance']) {
            $query->selectRaw($expression->sql.' AS benchmark_distance_meters', $expression->bindings);
        }

        if ($operation['minimum'] !== null) {
            $query->whereRaw($expression->sql.' BETWEEN CAST(? AS DOUBLE) AND CAST(? AS DOUBLE)', [
                ...$expression->bindings,
                MySqlDistanceExpression::bindNumber((float) $operation['minimum']),
                MySqlDistanceExpression::bindNumber((float) $operation['radius']),
            ]);
        } elseif ($operation['radius'] !== null) {
            $query->whereRaw($expression->sql.' <= CAST(? AS DOUBLE)', [
                ...$expression->bindings, MySqlDistanceExpression::bindNumber((float) $operation['radius']),
            ]);
        }

        if ($operation['nearest']) {
            $query->whereRaw($expression->validCoordinatesSql)
                ->orderByRaw($expression->sql.' ASC', $expression->bindings)->limit(10);
        }
    } else {
        if ($operation['minimum'] !== null) {
            $query->betweenDistances($origin, Distance::fromMeters($operation['minimum']), Distance::fromMeters($operation['radius']));
        } elseif ($operation['radius'] !== null) {
            $query->withinRadius($origin, Distance::fromMeters($operation['radius']));
        }

        if ($operation['nearest']) {
            $query->nearestTo($origin, limit: 10);
        } elseif ($operation['distance']) {
            $query->withDistance($origin);
        }

        if ($variant === 'selected_alias') {
            preg_match('/ AS (`__nearby_[0-9a-f]+`)/', $query->toSql(), $matches);

            if (!isset($matches[1])) {
                throw new RuntimeException('Unable to locate the internal selected-distance alias.');
            }

            $query->reorder()->orderByRaw($matches[1].' ASC');
        }
    }

    if ($variant === 'baseline' || !$operation['nearest'] || $variant === 'selected_alias') {
        $query->orderBy($model->qualifyColumn('id'));
    }

    return $query;
}

/** @return array{sql:string,bindings:array} */
function compile(Builder $query): array
{
    $base = $query->toBase();

    return ['sql' => $base->toSql(), 'bindings' => $base->getConnection()->prepareBindings($base->getBindings())];
}

function execute(PDO $pdo, array $query, string $prefix = ''): array
{
    $statement = $pdo->prepare($prefix.$query['sql']);

    foreach ($query['bindings'] as $index => $value) {
        $statement->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }

    $statement->execute();
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $statement->closeCursor();

    return $rows;
}

/** @return array{milliseconds:float,count:int,ids_sha256:string} */
function sample(PDO $pdo, array $query): array
{
    $start = hrtime(true);
    $rows = execute($pdo, $query);
    $milliseconds = (hrtime(true) - $start) / 1.0e6;
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

    return ['milliseconds' => $milliseconds, 'count' => count($ids), 'ids_sha256' => hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR))];
}

function median(array $values): float
{
    sort($values, SORT_NUMERIC);
    $middle = intdiv(count($values), 2);

    return count($values) % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2.0 : $values[$middle];
}

/** @return array<string, mixed> */
function plans(PDO $pdo, array $query, string $format, bool $analyze): array
{
    $result = [];

    if ($format === 'json' || $format === 'both') {
        $rows = execute($pdo, $query, 'EXPLAIN FORMAT=JSON ');
        $result['json'] = json_decode(array_values($rows[0])[0], true, flags: JSON_THROW_ON_ERROR);
    }

    if ($format === 'tree' || $format === 'both') {
        $result['tree'] = array_values(execute($pdo, $query, 'EXPLAIN FORMAT=TREE ')[0])[0];
    }

    if ($analyze) {
        $result['analyze'] = array_values(execute($pdo, $query, 'EXPLAIN ANALYZE FORMAT=TREE ')[0])[0];
    }

    return $result;
}

function compare(PDO $pdo, array $queries, int $warmups, int $repetitions, string $planFormat, bool $analyze): array
{
    $timings = array_fill_keys(array_keys($queries), []);
    $expected = null;

    for ($iteration = -$warmups; $iteration < $repetitions; $iteration++) {
        $variants = array_keys($queries);

        // Alternate execution order to reduce a consistent cache-order advantage.
        if (abs($iteration) % 2 === 1) {
            $variants = array_reverse($variants);
        }

        foreach ($variants as $variant) {
            $sample = sample($pdo, $queries[$variant]);
            $signature = [$sample['count'], $sample['ids_sha256']];
            $expected ??= $signature;

            if ($signature !== $expected) {
                throw new RuntimeException('Baseline and optimized ordered result IDs differ for '.$variant.'.');
            }

            if ($iteration >= 0) {
                $timings[$variant][] = $sample['milliseconds'];
            }
        }
    }

    $results = [];

    foreach ($queries as $variant => $query) {
        $results[$variant] = [
            'median_ms' => median($timings[$variant]),
            'minimum_ms' => min($timings[$variant]),
            'maximum_ms' => max($timings[$variant]),
            'samples_ms' => $timings[$variant],
            'rows_returned' => $expected[0],
            'ordered_ids_sha256' => $expected[1],
            'sql' => $query['sql'],
            'bindings' => $query['bindings'],
            'explain' => plans($pdo, $query, $planFormat, $analyze),
        ];
    }

    return $results;
}

try {
    $options = options(array_slice($argv, 1));

    if (isset($options['help']) || !isset($options['run'])) {
        echo usage(), "\n";
        exit(0);
    }

    $sizes = array_map(static fn (string $value): int => integerOption($value, 1, 1000000), explode(',', $options['sizes'] ?? '1000'));
    $distributions = choices($options['distributions'] ?? 'uniform,clustered', ['uniform', 'clustered']);
    $indexColumns = [
        'none' => [],
        'latitude' => ['latitude'],
        'longitude' => ['longitude'],
        'latitude_longitude' => ['latitude', 'longitude'],
        'longitude_latitude' => ['longitude', 'latitude'],
        'application_first' => ['tenant_id', 'is_active', 'latitude', 'longitude'],
    ];
    $indexes = choices($options['indexes'] ?? implode(',', array_keys($indexColumns)), array_keys($indexColumns));
    $operations = operations();
    $selectedOperations = choices($options['operations'] ?? implode(',', array_keys($operations)), array_keys($operations));
    $warmups = integerOption($options['warmups'] ?? '2', 0, 20);
    $repetitions = integerOption($options['repetitions'] ?? '5', 3, 100);
    $planFormat = choices($options['plans'] ?? 'json', ['json', 'tree', 'both', 'none']);

    if (count($planFormat) !== 1) {
        throw new InvalidArgumentException('Choose one EXPLAIN format.');
    }

    $database = getenv('DB_DATABASE') ?: '';
    $host = getenv('DB_HOST') ?: '';
    $username = getenv('DB_USERNAME') ?: '';

    if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*_benchmark\z/', $database) !== 1 || $host === '' || $username === '') {
        throw new InvalidArgumentException('Set DB_HOST, DB_USERNAME, and a dedicated DB_DATABASE ending in _benchmark.');
    }

    $port = integerOption(getenv('DB_PORT') ?: '3306', 1, 65535);
    $path = $options['output'] ?? 'build/nearby-benchmark-'.gmdate('Ymd-His').'.json';

    if (preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) !== 1) {
        $path = getcwd().DIRECTORY_SEPARATOR.$path;
    }

    if (!str_ends_with(strtolower($path), '.json') || file_exists($path)) {
        throw new InvalidArgumentException('Choose a new .json output path; existing files are not overwritten.');
    }

    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('Unable to create the report directory.');
    }

    $capsule = new Capsule();
    $capsule->addConnection([
        'driver' => 'mysql', 'host' => $host, 'port' => $port, 'database' => $database,
        'username' => $username, 'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        'options' => [PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false],
    ], 'benchmark');
    Model::setConnectionResolver($capsule->getDatabaseManager());
    $pdo = $capsule->getConnection('benchmark')->getPdo();
    $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

    if (stripos($version, 'MariaDB') !== false || preg_match('/\A8\.4\./', $version) !== 1) {
        throw new RuntimeException('Benchmarks require MySQL 8.4; connected server reports '.$version.'.');
    }

    $table = 'nearby_benchmark_'.bin2hex(random_bytes(6));
    $quotedTable = quoteIdentifier($table);
    $model = (new BenchmarkLocation())->setTable($table)->setConnection('benchmark');
    $origin = new Coordinate(35.6892, 51.3890);
    $report = [
        'completed' => false,
        'started_at_utc' => gmdate(DATE_ATOM),
        'environment' => [
            'php' => PHP_VERSION, 'os_family' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
            'mysql' => $version,
            'laravel' => InstalledVersions::isInstalled('laravel/framework') ? InstalledVersions::getPrettyVersion('laravel/framework') : null,
            'illuminate_database' => InstalledVersions::isInstalled('illuminate/database') ? InstalledVersions::getPrettyVersion('illuminate/database') : null,
            'mysql_settings' => $pdo->query('SELECT @@innodb_buffer_pool_size AS innodb_buffer_pool_size, @@tmp_table_size AS tmp_table_size, @@max_heap_table_size AS max_heap_table_size')->fetch(PDO::FETCH_ASSOC),
        ],
        'methodology' => [
            'timer' => 'Native prepared statement creation, execution and complete associative result fetch; excludes builder compilation, hashing and model hydration.',
            'baseline' => 'Current exact Haversine expression with bound parameters and no geographic prefilter; not a separate approximate algorithm.',
            'optimized' => 'SQL compiled from the public Eloquent proximity scopes.',
            'warmups_per_query' => $warmups, 'measured_repetitions' => $repetitions,
            'execution_order' => 'Alternates forward and reverse variant order each iteration.',
            'dataset_seed' => 104729, 'origin' => ['latitude' => $origin->latitude, 'longitude' => $origin->longitude],
            'uniform' => 'Uniform on the spherical surface: longitude uniform and latitude inverse-sine distributed.',
            'clustered' => '80% Gaussian coordinates around the origin (latitude sigma 0.6 degrees, longitude adjusted for latitude), 20% uniform on the globe.',
            'application_filter' => ['tenant_id' => 3, 'is_active' => true],
            'operations' => array_intersect_key($operations, array_flip($selectedOperations)),
            'analyze_executes_queries' => isset($options['analyze']),
            'index_statistics' => 'ANALYZE TABLE after each index change; ordinary InnoDB table with an id primary key.',
        ],
        'table' => $table,
        'results' => [],
    ];
    writeReport($path, $report);
    $pdo->exec('CREATE TABLE '.$quotedTable.' (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, latitude DOUBLE NOT NULL, longitude DOUBLE NOT NULL, tenant_id INT UNSIGNED NOT NULL, is_active TINYINT UNSIGNED NOT NULL) ENGINE=InnoDB');
    echo 'MySQL ', $version, '; report: ', $path, "\n";

    try {
        foreach ($sizes as $size) {
            foreach ($distributions as $distribution) {
                $fingerprint = seed($pdo, $table, $size, $distribution, $origin);
                $currentIndex = false;

                foreach ($indexes as $index) {
                    if ($currentIndex) {
                        $pdo->exec('ALTER TABLE '.$quotedTable.' DROP INDEX benchmark_candidate');
                    }

                    $columns = $indexColumns[$index];
                    $currentIndex = $columns !== [];

                    if ($currentIndex) {
                        $pdo->exec('ALTER TABLE '.$quotedTable.' ADD INDEX benchmark_candidate ('.implode(', ', array_map(quoteIdentifier(...), $columns)).')');
                    }

                    $pdo->query('ANALYZE TABLE '.$quotedTable)->fetchAll(PDO::FETCH_ASSOC);
                    $entry = ['size' => $size, 'distribution' => $distribution, 'dataset_sha256' => $fingerprint,
                        'index' => $index, 'index_columns' => $columns, 'operations' => []];

                    foreach ($selectedOperations as $operation) {
                        $variants = ['baseline', 'optimized'];

                        if (isset($options['investigate-alias']) && $operations[$operation]['nearest']) {
                            $variants[] = 'selected_alias';
                        }

                        $queries = [];

                        foreach ($variants as $variant) {
                            $queries[$variant] = compile(buildQuery($model, $origin, $operations[$operation], $variant));
                        }

                        $results = compare($pdo, $queries, $warmups, $repetitions, $planFormat[0], isset($options['analyze']));
                        $entry['operations'][$operation] = $results;
                        printf("%s %d %s %s: %d rows; baseline %.3f ms; optimized %.3f ms%s\n",
                            $distribution, $size, $index, $operation, $results['baseline']['rows_returned'],
                            $results['baseline']['median_ms'], $results['optimized']['median_ms'],
                            isset($results['selected_alias']) ? sprintf('; selected alias %.3f ms', $results['selected_alias']['median_ms']) : '');
                    }

                    $report['results'][] = $entry;
                    writeReport($path, $report);
                }

                if ($currentIndex) {
                    $pdo->exec('ALTER TABLE '.$quotedTable.' DROP INDEX benchmark_candidate');
                }
            }
        }

        $report['completed'] = true;
        $report['finished_at_utc'] = gmdate(DATE_ATOM);
        writeReport($path, $report);
    } finally {
        $pdo->exec('DROP TABLE '.$quotedTable);
    }

    echo 'Verified identical ordered result IDs for every sample. Completed report: ', $path, "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Benchmark failed: '.$exception->getMessage()."\n");
    exit(1);
}
