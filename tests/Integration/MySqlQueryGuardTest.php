<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Exceptions\UnsupportedDatabaseException;
use Zarbinco\Nearby\Tests\Integration\Fixtures\Branch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\CountingMySqlPdo;
use Zarbinco\Nearby\Tests\Integration\Fixtures\ScopedBranch;

final class MySqlQueryGuardTest extends MySqlTestCase
{
    public function test_chained_scopes_clones_compilation_and_repeated_execution_share_one_physical_pdo_check(): void
    {
        $this->branch('Eligible', 0, 0.01);
        $pdo = $this->countingPdo();
        DB::connection()->setPdo($pdo)->setReadPdo($pdo);
        $origin = new Coordinate(0, 0);
        $query = Branch::query()->withDistance($origin)->withinRadius($origin, Distance::fromKilometers(5))
            ->betweenDistances($origin, Distance::fromMeters(0), Distance::fromKilometers(5))
            ->orderByDistance($origin)->nearestTo($origin, 10);

        self::assertSame(0, $pdo->serverVersionReads);
        $query->toSql();
        self::assertSame(1, $pdo->serverVersionReads);
        self::assertCount(1, $query->get());
        self::assertCount(1, (clone $query)->get());
        self::assertCount(1, $query->get());
        self::assertSame(1, (clone $query)->count());
        self::assertTrue((clone $query)->exists());
        self::assertSame(1, $pdo->serverVersionReads);
    }

    public function test_a_new_query_family_performs_its_own_validation_without_a_process_wide_cache(): void
    {
        $this->branch('Eligible', 0, 0.01);
        $pdo = $this->countingPdo();
        DB::connection()->setPdo($pdo)->setReadPdo($pdo);
        $origin = new Coordinate(0, 0);

        Branch::query()->withDistance($origin)->get();
        Branch::query()->withDistance($origin)->get();

        self::assertSame(2, $pdo->serverVersionReads);
    }

    public function test_the_guard_checks_read_and_write_pdos_independently(): void
    {
        $this->branch('Eligible', 0, 0.01);
        $read = $this->countingPdo();
        $write = $this->countingPdo();
        DB::connection()->setPdo($write)->setReadPdo($read);
        $query = Branch::query()->withDistance(new Coordinate(0, 0));

        self::assertCount(1, (clone $query)->get());
        self::assertSame(1, $read->serverVersionReads);
        self::assertSame(0, $write->serverVersionReads);

        self::assertCount(1, (clone $query)->useWritePdo()->get());
        self::assertSame(1, $write->serverVersionReads);
        self::assertCount(1, (clone $query)->get());
        self::assertSame(1, $read->serverVersionReads);
    }

    public function test_replacing_a_pdo_after_compilation_rechecks_the_new_physical_connection(): void
    {
        $this->branch('Eligible', 0, 0.01);
        $initial = $this->countingPdo();
        DB::connection()->setPdo($initial)->setReadPdo($initial);
        $query = Branch::query()->withDistance(new Coordinate(0, 0));
        $query->toSql();

        $replacement = $this->countingPdo();
        DB::connection()->setPdo($replacement)->setReadPdo($replacement);

        self::assertCount(1, $query->get());
        self::assertSame(1, $initial->serverVersionReads);
        self::assertSame(1, $replacement->serverVersionReads);
    }

    #[DataProvider('unsupportedVersions')]
    public function test_an_unsupported_pdo_replacement_is_rejected_after_to_sql_and_cloning(string $version): void
    {
        $initial = $this->countingPdo();
        DB::connection()->setPdo($initial)->setReadPdo($initial);
        $query = Branch::query()->withDistance(new Coordinate(0, 0));
        $query->toSql();
        $clone = clone $query;
        $replacement = $this->countingPdo();
        $replacement->reportedVersion = $version;
        DB::connection()->setPdo($replacement)->setReadPdo($replacement);
        $this->expectException(UnsupportedDatabaseException::class);
        $this->expectExceptionMessage($version);

        try {
            $clone->get();
        } finally {
            self::assertSame(1, $replacement->serverVersionReads);
        }
    }

    public static function unsupportedVersions(): iterable
    {
        yield 'older MySQL' => ['8.0.42'];
        yield 'later unverified MySQL' => ['9.0.0'];
        yield 'MariaDB' => ['11.4.3-MariaDB'];
        yield 'MariaDB compatibility prefix' => ['8.4.3-MariaDB'];
    }

    public function test_an_unsupported_write_pdo_cannot_use_a_cached_read_pdo_validation(): void
    {
        $read = $this->countingPdo();
        $write = $this->countingPdo();
        $write->reportedVersion = '8.0.42';
        DB::connection()->setPdo($write)->setReadPdo($read);
        $query = Branch::query()->withDistance(new Coordinate(0, 0));
        (clone $query)->get();
        $this->expectException(UnsupportedDatabaseException::class);

        (clone $query)->useWritePdo()->get();
    }

    public function test_removing_application_global_scopes_before_proximity_keeps_version_validation(): void
    {
        $pdo = $this->countingPdo();
        $pdo->reportedVersion = '11.4.3-MariaDB';
        DB::connection()->setPdo($pdo)->setReadPdo($pdo);
        $this->expectException(UnsupportedDatabaseException::class);

        ScopedBranch::query()->withoutGlobalScopes()->withinRadius(new Coordinate(0, 0), Distance::fromMeters(1))->get();
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
}
