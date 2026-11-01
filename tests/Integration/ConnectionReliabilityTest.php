<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Eloquent\MySqlQueryBuilder;
use Zarbinco\Nearby\Exceptions\UnsupportedDatabaseException;
use Zarbinco\Nearby\Tests\Integration\Fixtures\Branch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\CountingMySqlPdo;
use Zarbinco\Nearby\Tests\Integration\Fixtures\CustomBaseQueryBuilder;
use Zarbinco\Nearby\Tests\Integration\Fixtures\CustomBuilderBranch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\CustomMySqlConnection;
use Zarbinco\Nearby\Tests\Integration\Fixtures\InheritedCustomBuilderBranch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\ObservedMySqlPdo;
use Zarbinco\Nearby\Tests\Integration\Fixtures\ObservedMySqlStatement;

final class ConnectionReliabilityTest extends MySqlTestCase
{
    #[DataProvider('unsupportedInFlightReplacements')]
    public function test_an_in_flight_retry_rejects_an_unsupported_replacement_before_preparing_geographic_sql(
        string $method,
        bool $write,
        bool $clone,
        string $version,
    ): void {
        $this->branch('Eligible', 0, 0.01);
        $connection = DB::connection();
        $initial = $this->observedPdo();
        $connection->setPdo($initial)->setReadPdo($initial);
        $query = $this->retryQuery($write);
        self::assertCount(1, $query->get());
        $replacement = null;
        $reconnections = 0;
        $connection->setReconnector(function (Connection $connection) use (&$replacement, &$reconnections, $version): Connection {
            $reconnections++;
            $replacement = $this->observedPdo();
            // The transport is real MySQL 8.4; only the reported version is simulated.
            $replacement->reportedVersion = $version;

            return $connection->setPdo($replacement)->setReadPdo($replacement);
        });
        $this->terminateOwnSession($initial);
        $rejected = false;

        try {
            $this->retrieve($clone ? clone $query : $query, $method);
        } catch (UnsupportedDatabaseException $exception) {
            $rejected = true;
            self::assertStringContainsString($version, $exception->getMessage());
        }

        self::assertSame(1, $reconnections);
        self::assertInstanceOf(ObservedMySqlPdo::class, $replacement);
        $observation = sprintf(
            'Replacement observation: version_reads=%d, geographic_prepares=%d, geographic_executes=%d.',
            $replacement->serverVersionReads,
            $replacement->geographicPrepareAttempts,
            $replacement->geographicExecuteAttempts,
        );
        self::assertSame(0, $replacement->geographicPrepareAttempts, $observation);
        self::assertSame(0, $replacement->geographicExecuteAttempts, $observation);
        self::assertSame(1, $replacement->serverVersionReads, $observation);
        self::assertTrue($rejected, 'An unsupported replacement must raise UnsupportedDatabaseException.');
        self::assertSame(['version'], $replacement->events);
    }

    public static function unsupportedInFlightReplacements(): iterable
    {
        foreach (self::retryRetrievalMethods() as $name => [$method, $write, $clone]) {
            foreach (['8.0.42', '11.4.3-MariaDB'] as $version) {
                yield $name.' '.$version => [$method, $write, $clone, $version];
            }
        }
    }

    public static function retryRetrievalMethods(): iterable
    {
        yield 'get using read PDO' => ['get', false, false];
        yield 'count using cloned read query' => ['count', false, true];
        yield 'exists using write PDO' => ['exists', true, false];
        yield 'cursor using cloned write query' => ['cursor', true, true];
        yield 'explain using cloned read query' => ['explain', false, true];
    }

    public function test_a_custom_base_builder_remains_usable_but_rejects_geographic_scopes_before_sql_preparation(): void
    {
        $branch = $this->branch('Eligible', 0, 0.01);
        $pdo = $this->observedPdo();
        DB::connection()->setPdo($pdo)->setReadPdo($pdo);
        $query = CustomBuilderBranch::query()->where('name', 'Eligible');
        $base = $query->getQuery();

        self::assertInstanceOf(CustomBaseQueryBuilder::class, $base);
        self::assertSame($base, MySqlQueryBuilder::wrap($base));
        self::assertSame('application-builder-state', $base->customState());
        self::assertSame([$branch->id], (clone $query)->get()->modelKeys());
        $rejected = false;

        try {
            $query->withDistance(new Coordinate(0, 0))->get();
        } catch (UnsupportedDatabaseException $exception) {
            $rejected = true;
            self::assertStringContainsString('standard Eloquent base query builder', $exception->getMessage());
        }

        self::assertTrue($rejected);
        self::assertSame(0, $pdo->geographicPrepareAttempts);
        self::assertSame(0, $pdo->geographicExecuteAttempts);
        self::assertSame([$branch->id], $query->get()->modelKeys());
        self::assertSame('application-builder-state', $base->customState());
    }

    public function test_replacing_a_guarded_eloquent_base_builder_cannot_bypass_execution_safety(): void
    {
        $this->branch('Eligible', 0, 0.01);
        $connection = DB::connection();
        $pdo = $this->observedPdo();
        $connection->setPdo($pdo)->setReadPdo($pdo);
        $query = $this->retryQuery(false);
        $base = $query->getQuery();
        $replacement = new CustomBaseQueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());

        foreach (get_object_vars($base) as $property => $value) {
            $replacement->{$property} = $value;
        }

        $query->setQuery($replacement);
        $this->expectException(UnsupportedDatabaseException::class);
        $this->expectExceptionMessage('standard Eloquent base query builder');

        try {
            $query->get();
        } finally {
            self::assertSame(0, $pdo->geographicPrepareAttempts);
            self::assertSame(0, $pdo->geographicExecuteAttempts);
        }
    }

    public function test_inherited_application_builder_factories_keep_their_private_state_and_query_behavior(): void
    {
        $active = $this->branch('Active', 0, 0.01);
        $this->branch('Inactive', 0, 0.01, ['is_active' => false]);
        $pdo = $this->observedPdo();
        DB::connection()->setPdo($pdo)->setReadPdo($pdo);
        $query = InheritedCustomBuilderBranch::query();
        $base = $query->getQuery();

        self::assertInstanceOf(CustomBaseQueryBuilder::class, $base);
        self::assertSame('application-builder-state', $base->customState());
        self::assertSame([$active->id], (clone $query)->get()->modelKeys());
        $rejected = false;

        try {
            $query->withDistance(new Coordinate(0, 0))->get();
        } catch (UnsupportedDatabaseException $exception) {
            $rejected = true;
            self::assertStringContainsString('standard Eloquent base query builder', $exception->getMessage());
        }

        self::assertTrue($rejected);
        self::assertSame(0, $pdo->geographicPrepareAttempts);
        self::assertSame(0, $pdo->geographicExecuteAttempts);
        self::assertSame([$active->id], $query->get()->modelKeys());
    }

    public function test_custom_connection_execution_remains_available_only_for_ordinary_queries(): void
    {
        $branch = $this->branch('Eligible', 0, 0.01);
        $pdo = $this->observedPdo();
        $config = config('database.connections.nearby_test_mysql');
        $custom = new CustomMySqlConnection($pdo, $config['database'], '', $config);
        $custom->setReadPdo($pdo);
        $ordinary = Branch::query()->where('name', 'Eligible');
        $ordinary->getQuery()->connection = $custom;

        self::assertSame([$branch->id], $ordinary->get()->modelKeys());
        self::assertSame(1, $custom->selectCalls);

        foreach ([false, true] as $compileBeforeReplacement) {
            $query = Branch::query()->withDistance(new Coordinate(0, 0));
            if ($compileBeforeReplacement) {
                $query->withoutGlobalScopes()->toSql();
            }
            $query->getQuery()->connection = $custom;
            $rejected = false;

            try {
                $query->get();
            } catch (UnsupportedDatabaseException $exception) {
                $rejected = true;
                self::assertStringContainsString('standard MySQL connection', $exception->getMessage());
            }

            self::assertTrue($rejected);
        }

        self::assertSame(1, $custom->selectCalls);
        self::assertSame(0, $pdo->geographicPrepareAttempts);
        self::assertSame(0, $pdo->geographicExecuteAttempts);
        self::assertSame([$branch->id], $ordinary->get()->modelKeys());
        self::assertSame(2, $custom->selectCalls);
    }

    #[DataProvider('retryRetrievalMethods')]
    public function test_a_supported_in_flight_replacement_is_validated_before_statement_preparation(
        string $method,
        bool $write,
        bool $clone,
    ): void {
        $branch = $this->branch('Eligible', 0, 0.01);
        $this->branch('Outside radius', 0, 1);
        $connection = DB::connection();
        $initial = $this->observedPdo();
        $connection->setPdo($initial)->setReadPdo($initial);
        $query = $this->retryQuery($write);
        self::assertCount(1, $query->get());
        $replacement = null;
        $reconnections = 0;
        $connection->setReconnector(function (Connection $connection) use (&$replacement, &$reconnections): Connection {
            $reconnections++;
            $replacement = $this->observedPdo();

            return $connection->setPdo($replacement)->setReadPdo($replacement);
        });
        $this->terminateOwnSession($initial);

        $this->assertRetrievedResult($this->retrieve($clone ? clone $query : $query, $method), $method, $branch->id);

        self::assertSame(1, $reconnections);
        self::assertInstanceOf(ObservedMySqlPdo::class, $replacement);
        self::assertSame(1, $replacement->serverVersionReads);
        self::assertSame(1, $replacement->geographicPrepareAttempts);
        self::assertSame(1, $replacement->geographicExecuteAttempts);
        self::assertSame(['version', 'prepare', 'execute'], $replacement->events);
        $this->assertRetrievedResult($this->retrieve(clone $query, $method), $method, $branch->id);
        self::assertSame(1, $replacement->serverVersionReads);
        self::assertSame(1, $reconnections);
    }

    public function test_a_lost_session_inside_a_transaction_does_not_retry_on_a_replacement(): void
    {
        $this->branch('Eligible', 0, 0.01);
        $connection = DB::connection();
        $initial = $this->observedPdo();
        $connection->setPdo($initial)->setReadPdo($initial);
        $query = $this->retryQuery(true);
        $query->get();
        $reconnections = 0;
        $connection->setReconnector(function (Connection $connection) use (&$reconnections): Connection {
            $reconnections++;
            $pdo = $this->observedPdo();

            return $connection->setPdo($pdo)->setReadPdo($pdo);
        });
        $connection->beginTransaction();
        $this->terminateOwnSession($initial);
        $failed = false;

        try {
            $query->get();
        } catch (QueryException $exception) {
            $failed = true;
            self::assertInstanceOf(\PDOException::class, $exception->getPrevious());
        } finally {
            // The server has already discarded this transaction; restore a live test PDO.
            $restored = $this->observedPdo();
            $connection->setPdo($restored)->setReadPdo($restored);
        }

        self::assertTrue($failed, 'Laravel must retain its no-retry transaction behavior.');
        self::assertSame(0, $reconnections);
    }

    #[DataProvider('unsupportedReconnectedVersions')]
    public function test_nested_geographic_where_conditions_validate_an_in_flight_replacement(string $version): void
    {
        $this->branch('Eligible', 0, 0.01);
        $connection = DB::connection();
        $initial = $this->observedPdo();
        $connection->setPdo($initial)->setReadPdo($initial);
        $query = Branch::query()->where(static function (Builder $query): void {
            $query->withinRadius(new Coordinate(0, 0), Distance::fromKilometers(5));
        });
        self::assertCount(1, $query->get());
        self::assertSame(1, $initial->serverVersionReads);
        $replacement = null;
        $connection->setReconnector(function (Connection $connection) use (&$replacement, $version): Connection {
            $replacement = $this->observedPdo();
            $replacement->reportedVersion = $version;

            return $connection->setPdo($replacement)->setReadPdo($replacement);
        });
        $this->terminateOwnSession($initial);
        $this->expectException(UnsupportedDatabaseException::class);

        try {
            (clone $query)->get();
        } finally {
            self::assertInstanceOf(ObservedMySqlPdo::class, $replacement);
            self::assertSame(1, $replacement->serverVersionReads);
            self::assertSame(0, $replacement->geographicPrepareAttempts);
            self::assertSame(0, $replacement->geographicExecuteAttempts);
        }
    }

    public function test_removing_global_scopes_cannot_bypass_replacement_validation_during_a_retry(): void
    {
        $this->branch('Eligible', 0, 0.01);
        $connection = DB::connection();
        $initial = $this->observedPdo();
        $connection->setPdo($initial)->setReadPdo($initial);
        $query = $this->retryQuery(false);
        self::assertCount(1, $query->get());
        $query->withoutGlobalScopes()->toSql();
        $replacement = null;
        $connection->setReconnector(function (Connection $connection) use (&$replacement): Connection {
            $replacement = $this->observedPdo();
            $replacement->reportedVersion = '11.4.3-MariaDB';

            return $connection->setPdo($replacement)->setReadPdo($replacement);
        });
        $this->terminateOwnSession($initial);
        $this->expectException(UnsupportedDatabaseException::class);
        $this->expectExceptionMessage('11.4.3-MariaDB');

        try {
            $query->get();
        } finally {
            self::assertInstanceOf(ObservedMySqlPdo::class, $replacement);
            self::assertSame(1, $replacement->serverVersionReads);
            self::assertSame(0, $replacement->geographicPrepareAttempts);
            self::assertSame(0, $replacement->geographicExecuteAttempts);
            self::assertSame(['version'], $replacement->events);
        }
    }

    public function test_a_reused_query_reconnects_automatically_after_disconnection(): void
    {
        $branch = $this->branch('Eligible', 0, 0.01);
        $connection = DB::connection();
        $initialPdo = $connection->getPdo();
        $origin = new Coordinate(0, 0);
        $query = Branch::query()->withinRadius($origin, Distance::fromKilometers(5))->withDistance($origin);

        self::assertSame([$branch->id], $query->get()->modelKeys());
        $connection->disconnect();
        self::assertNull($connection->getRawPdo());
        self::assertNull($connection->getRawReadPdo());
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $results = $query->get();

        self::assertSame([$branch->id], $results->modelKeys());
        self::assertIsFloat($results->first()->nearby_distance_meters);
        self::assertNotSame($initialPdo, $connection->getPdo());
        self::assertSame(1, (clone $query)->count());
        self::assertTrue((clone $query)->exists());
        self::assertCount(3, $connection->getQueryLog(), 'Validation must not issue a separate version query.');
        $connection->disableQueryLog();
    }

    public function test_explicit_reconnection_revalidates_the_new_physical_pdo_for_retained_builders(): void
    {
        $branch = $this->branch('Eligible', 0, 0.01);
        $connection = DB::connection();
        $initial = $this->countingPdo();
        $connection->setPdo($initial)->setReadPdo($initial);
        $query = Branch::query()->withDistance(new Coordinate(0, 0));
        self::assertSame([$branch->id], $query->get()->modelKeys());
        $replacement = null;
        $connection->setReconnector(function (Connection $connection) use (&$replacement): Connection {
            $replacement = $this->countingPdo();

            return $connection->setPdo($replacement)->setReadPdo($replacement);
        });

        $connection->disconnect();
        $connection->reconnect();

        self::assertInstanceOf(CountingMySqlPdo::class, $replacement);
        self::assertNotSame($initial, $replacement);
        self::assertSame([$branch->id], (clone $query)->get()->modelKeys());
        self::assertSame(1, $initial->serverVersionReads);
        self::assertSame(1, $replacement->serverVersionReads);
        self::assertTrue((clone $query)->exists());
        self::assertSame(1, $replacement->serverVersionReads);
    }

    #[DataProvider('unsupportedReconnectedVersions')]
    public function test_automatic_reconnection_cannot_reuse_an_earlier_pdo_validation(string $version): void
    {
        $this->branch('Eligible', 0, 0.01);
        $connection = DB::connection();
        $query = Branch::query()->withDistance(new Coordinate(0, 0));
        $query->get();
        $replacement = null;
        $connection->setReconnector(function (Connection $connection) use (&$replacement, $version): Connection {
            $replacement = $this->countingPdo();
            // Only the reported version is simulated; this is a real MySQL PDO.
            $replacement->reportedVersion = $version;

            return $connection->setPdo($replacement)->setReadPdo($replacement);
        });
        $connection->disconnect();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $this->expectException(UnsupportedDatabaseException::class);
        $this->expectExceptionMessage($version);

        try {
            $query->get();
        } finally {
            self::assertInstanceOf(CountingMySqlPdo::class, $replacement);
            self::assertSame(1, $replacement->serverVersionReads);
            self::assertSame([], $connection->getQueryLog());
            $connection->disableQueryLog();
        }
    }

    public static function unsupportedReconnectedVersions(): iterable
    {
        yield 'older MySQL' => ['8.0.42'];
        yield 'MariaDB' => ['11.4.3-MariaDB'];
    }

    public function test_query_construction_after_disconnect_defers_reconnection_until_execution(): void
    {
        $this->branch('Eligible', 0, 0.01);
        $connection = DB::connection();
        $reconnections = 0;
        $connection->setReconnector(function (Connection $connection) use (&$reconnections): Connection {
            $reconnections++;
            $pdo = $this->countingPdo();

            return $connection->setPdo($pdo)->setReadPdo($pdo);
        });
        $connection->disconnect();

        $origin = new Coordinate(0, 0);
        $query = Branch::query()->withinRadius($origin, Distance::fromKilometers(5))
            ->nearestTo($origin)->where('is_active', true);
        $clone = clone $query;

        self::assertSame(0, $reconnections);
        self::assertNull($connection->getRawPdo());
        self::assertCount(1, $clone->get());
        self::assertSame(1, $reconnections);
        self::assertCount(1, $query->get());
        self::assertSame(1, $reconnections);
    }

    public function test_a_lost_mysql_session_recovers_and_the_retained_query_checks_its_replacement(): void
    {
        $branch = $this->branch('Eligible', 0, 0.01);
        $connection = DB::connection();
        $initial = $this->countingPdo();
        $connection->setPdo($initial)->setReadPdo($initial);
        $origin = new Coordinate(0, 0);
        $query = Branch::query()->withinRadius($origin, Distance::fromKilometers(5))->nearestTo($origin);
        self::assertSame([$branch->id], $query->get()->modelKeys());
        $replacement = null;
        $connection->setReconnector(function (Connection $connection) use (&$replacement): Connection {
            $replacement = $this->countingPdo();

            return $connection->setPdo($replacement)->setReadPdo($replacement);
        });

        // Terminate only the session opened and identified by this test.
        $sessionId = (int) $initial->query('SELECT CONNECTION_ID()')->fetchColumn();
        self::assertGreaterThan(0, $sessionId);
        $control = $this->countingPdo();
        $control->exec('KILL CONNECTION '.$sessionId);

        $results = $query->get();

        self::assertSame([$branch->id], $results->modelKeys());
        self::assertIsFloat($results->first()->nearby_distance_meters);
        self::assertInstanceOf(CountingMySqlPdo::class, $replacement);
        self::assertNotSame($initial, $replacement);
        self::assertSame(1, (clone $query)->count());
        self::assertTrue((clone $query)->exists());
        self::assertSame(1, $initial->serverVersionReads);
        self::assertSame(1, $replacement->serverVersionReads);
    }

    public function test_a_transaction_validates_the_write_pdo_even_for_an_ordinary_read_query(): void
    {
        $this->branch('Eligible', 0, 0.01);
        $connection = DB::connection();
        $read = $this->countingPdo();
        $write = $this->countingPdo();
        $write->reportedVersion = '11.4.3-MariaDB';
        $connection->setPdo($write)->setReadPdo($read);
        $query = Branch::query()->withDistance(new Coordinate(0, 0));
        $connection->beginTransaction();
        $this->expectException(UnsupportedDatabaseException::class);

        try {
            $query->get();
        } finally {
            $connection->rollBack();
            self::assertSame(0, $read->serverVersionReads);
            self::assertSame(1, $write->serverVersionReads);
        }
    }

    public function test_multiple_model_instances_do_not_share_a_process_wide_validation_cache(): void
    {
        $branch = $this->branch('Eligible', 0, 0.01);
        $connection = DB::connection();
        $pdo = $this->countingPdo();
        $connection->setPdo($pdo)->setReadPdo($pdo);

        for ($request = 1; $request <= 4; $request++) {
            $model = new Branch();
            $origin = new Coordinate(0, $request / 1000.0);
            $query = $model->newQuery()->withinRadius($origin, Distance::fromKilometers(5))->withDistance($origin);

            self::assertSame([$branch->id], $query->get()->modelKeys());
            self::assertTrue((clone $query)->exists());
            self::assertSame($request, $pdo->serverVersionReads);
        }
    }

    #[DataProvider('unsupportedDrivers')]
    public function test_unsupported_drivers_are_rejected_without_opening_their_lazy_connections(string $driver): void
    {
        $name = 'nearby_reliability_'.$driver;
        $config = config('database.connections.nearby_test_mysql');
        $config['driver'] = $driver;

        if ($driver === 'sqlite') {
            $config['database'] = ':memory:';
        }

        config()->set('database.connections.'.$name, $config);
        $connection = DB::connection($name);
        self::assertInstanceOf(\Closure::class, $connection->getRawPdo());
        $connection->enableQueryLog();
        $this->expectException(UnsupportedDatabaseException::class);

        try {
            Branch::on($name)->withinRadius(new Coordinate(0, 0), Distance::fromKilometers(1))->get();
        } finally {
            self::assertInstanceOf(\Closure::class, $connection->getRawPdo());
            self::assertSame([], $connection->getQueryLog());
        }
    }

    public static function unsupportedDrivers(): iterable
    {
        yield 'PostgreSQL configuration' => ['pgsql'];
        yield 'SQLite configuration' => ['sqlite'];
        yield 'MariaDB configuration' => ['mariadb'];
    }

    private function countingPdo(): CountingMySqlPdo
    {
        $config = config('database.connections.nearby_test_mysql');

        return new CountingMySqlPdo(
            'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4',
            $config['username'],
            $config['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false],
        );
    }

    private function observedPdo(): ObservedMySqlPdo
    {
        $config = config('database.connections.nearby_test_mysql');
        $pdo = new ObservedMySqlPdo(
            'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4',
            $config['username'],
            $config['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false],
        );
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [ObservedMySqlStatement::class, [$pdo]]);

        return $pdo;
    }

    private function retryQuery(bool $write): Builder
    {
        $origin = new Coordinate(0, 0);
        $query = Branch::query()->where('is_active', true)->withinRadius($origin, Distance::fromKilometers(5))
            ->betweenDistances($origin, Distance::fromMeters(0), Distance::fromKilometers(5))
            ->withDistance($origin)->orderByDistance($origin)->nearestTo($origin, 3);

        return $write ? $query->useWritePdo() : $query;
    }

    private function terminateOwnSession(ObservedMySqlPdo $pdo): void
    {
        $sessionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        self::assertGreaterThan(0, $sessionId);
        $control = $this->observedPdo();
        $control->exec('KILL CONNECTION '.$sessionId);
    }

    private function retrieve(Builder $query, string $method): mixed
    {
        return $method === 'cursor' ? $query->cursor()->collect() : $query->$method();
    }

    private function assertRetrievedResult(mixed $result, string $method, int $id): void
    {
        if ($method === 'count') {
            self::assertSame(1, $result);
        } elseif ($method === 'exists') {
            self::assertTrue($result);
        } elseif ($method === 'explain') {
            self::assertInstanceOf(\Illuminate\Support\Collection::class, $result);
            self::assertNotEmpty($result);
            $plan = (array) $result->first();
            self::assertArrayHasKey('select_type', $plan);
            self::assertArrayHasKey('table', $plan);
        } else {
            self::assertSame([$id], $result->pluck('id')->all());
            self::assertIsFloat($result->first()->nearby_distance_meters);
        }
    }
}
