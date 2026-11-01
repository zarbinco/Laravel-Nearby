<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Benchmarks;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use Zarbinco\Nearby\Eloquent\MySqlDistanceExpression;
use Zarbinco\Nearby\Eloquent\MySqlQueryGuard;

require dirname(__DIR__).'/vendor/autoload.php';

final class GuardProfileModel extends Model
{
    protected $table = 'nearby_benchmark_guard';
}

final class CountedVersionPdo extends PDO
{
    public int $versionReads = 0;

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_SERVER_VERSION) {
            $this->versionReads++;
        }

        return parent::getAttribute($attribute);
    }
}

function profileQuery(GuardProfileModel $model, string $variant): Builder
{
    $query = $model->newQuery()->select('id');

    for ($expression = 0; $expression < 4; $expression++) {
        if ($variant === 'legacy_callbacks') {
            $query->getQuery()->beforeQuery(static function (QueryBuilder $base): void {
                if ($base->from !== 'nearby_benchmark_guard') {
                    throw new InvalidArgumentException('Unexpected profiling table.');
                }

                MySqlDistanceExpression::assertServerVersion($base->getConnection(), $base->useWritePdo);
            });
        } else {
            MySqlQueryGuard::register($query);
        }
    }

    return $query;
}

function profileMedian(array $values): float
{
    sort($values, SORT_NUMERIC);
    $middle = intdiv(count($values), 2);

    return count($values) % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2.0 : $values[$middle];
}

try {
    $options = getopt('', ['run', 'help', 'iterations:', 'repetitions:', 'output:']);

    if (isset($options['help']) || !isset($options['run'])) {
        echo "Usage: php benchmarks/profile-guard.php --run [--iterations=5000] [--repetitions=7] [--output=build/guard-profile.json]\n";
        echo "Requires DB_HOST, DB_USERNAME and DB_DATABASE ending in _benchmark; DB_PORT defaults to 3306.\n";
        echo "Connects to MySQL 8.4, compiles queries, and reads the real PDO server-version attribute. No SQL is executed and no tables are created.\n";
        exit(0);
    }

    $iterations = $options['iterations'] ?? '5000';
    $repetitions = $options['repetitions'] ?? '7';

    if (!ctype_digit($iterations) || (int) $iterations < 100 || (float) $iterations > 100000
        || !ctype_digit($repetitions) || (int) $repetitions < 3 || (float) $repetitions > 100) {
        throw new InvalidArgumentException('Use 100–100000 iterations and 3–100 repetitions.');
    }

    $iterations = (int) $iterations;
    $repetitions = (int) $repetitions;
    $database = getenv('DB_DATABASE') ?: '';
    $host = getenv('DB_HOST') ?: '';
    $username = getenv('DB_USERNAME') ?: '';
    $port = getenv('DB_PORT') ?: '3306';

    if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*_benchmark\z/', $database) !== 1 || $host === '' || $username === ''
        || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new InvalidArgumentException('Set the dedicated benchmark DB_* connection environment.');
    }

    $path = $options['output'] ?? 'build/guard-profile-'.gmdate('Ymd-His').'.json';

    if (!str_ends_with(strtolower($path), '.json') || file_exists($path)) {
        throw new InvalidArgumentException('Choose a new .json output path.');
    }

    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('Unable to create the output directory.');
    }

    $pdo = new CountedVersionPdo('mysql:host='.$host.';port='.$port.';dbname='.$database.';charset=utf8mb4', $username, getenv('DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'mysql', 'database' => $database], 'profile');
    $connection = $capsule->getConnection('profile');
    $connection->setPdo($pdo)->setReadPdo($pdo);
    Model::setConnectionResolver($capsule->getDatabaseManager());
    MySqlDistanceExpression::assertServerVersion($connection);
    $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $model = (new GuardProfileModel())->setConnection('profile');
    $report = [
        'php' => PHP_VERSION, 'mysql' => $version, 'iterations_per_batch' => $iterations,
        'repetitions' => $repetitions, 'warmups_per_variant' => 100,
        'methodology' => 'Four expressions per query. Compile a trivial select without executing SQL. Legacy variant registers four callbacks and checks the actual PDO version four times; current variant registers the query guard through its normal API. Fresh families include builder creation and guard registration. Cloned-family measurements reuse one builder family and physical PDO after warm-up. These PHP microseconds are not database-query latency improvements.',
        'cases' => [],
    ];

    foreach (['fresh_families', 'cloned_family'] as $case) {
        $timings = ['legacy_callbacks' => [], 'query_guard' => []];
        $counts = $timings;
        $families = [
            'legacy_callbacks' => profileQuery($model, 'legacy_callbacks'),
            'query_guard' => profileQuery($model, 'query_guard'),
        ];
        $actions = [];

        foreach (array_keys($families) as $variant) {
            $actions[$variant] = $case === 'fresh_families'
                ? static fn () => profileQuery($model, $variant)->toSql()
                : static fn () => (clone $families[$variant])->toSql();

            for ($warmup = 0; $warmup < 100; $warmup++) {
                $actions[$variant]();
            }
        }

        for ($batch = 0; $batch < $repetitions; $batch++) {
            $variants = $batch % 2 === 0 ? array_keys($actions) : array_reverse(array_keys($actions));

            foreach ($variants as $variant) {
                $pdo->versionReads = 0;
                $start = hrtime(true);

                for ($iteration = 0; $iteration < $iterations; $iteration++) {
                    $actions[$variant]();
                }

                $timings[$variant][] = (hrtime(true) - $start) / 1.0e6;
                $counts[$variant][] = $pdo->versionReads;
            }
        }

        foreach ($timings as $variant => $samples) {
            $report['cases'][$case][$variant] = [
                'median_batch_ms' => profileMedian($samples),
                'median_per_compilation_us' => profileMedian($samples) * 1000.0 / $iterations,
                'samples_batch_ms' => $samples,
                'pdo_version_reads_per_batch' => $counts[$variant],
            ];
            printf("%s %s: %.3f us/compilation; %s PDO version reads per %d compilations\n", $case, $variant,
                profileMedian($samples) * 1000.0 / $iterations, implode(',', $counts[$variant]), $iterations);
        }
    }

    if (file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n") === false) {
        throw new RuntimeException('Unable to write the guard profiling report.');
    }

    echo 'Guard profiling report: ', realpath($path), "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Guard profiling failed: '.$exception->getMessage()."\n");
    exit(1);
}
