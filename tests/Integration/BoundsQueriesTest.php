<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Zarbinco\Nearby\Bounds;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Exceptions\UnsupportedDatabaseException;
use Zarbinco\Nearby\Geo;
use Zarbinco\Nearby\Tests\Integration\Fixtures\BoundsObservedPdo;
use Zarbinco\Nearby\Tests\Integration\Fixtures\BoundsObservedStatement;
use Zarbinco\Nearby\Tests\Integration\Fixtures\Branch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\CustomBranch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\CustomBuilderBranch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\ScopedBranch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\UnsafeBranch;
use Zarbinco\Nearby\Unit;

final class BoundsQueriesTest extends MySqlTestCase
{
    #[DataProvider('viewports')]
    public function test_sql_results_match_contains_for_valid_coordinates_and_exclude_invalid_rows(Bounds $bounds): void
    {
        $coordinates = $this->seedCoordinates($bounds);
        $expected = [];
        foreach ($coordinates as $id => $coordinate) {
            if ($bounds->contains($coordinate)) {
                $expected[] = $id;
            }
        }

        $query = Branch::query()->withinBounds($bounds)->orderBy('id');

        self::assertSame($expected, (clone $query)->pluck('id')->all());
        self::assertSame(count($expected), (clone $query)->count());
        self::assertSame($expected !== [], (clone $query)->exists());
        self::assertSame($expected[0] ?? null, (clone $query)->first()?->id);
    }

    public static function viewports(): iterable
    {
        yield 'ordinary' => [new Bounds(-20, -30, 20, 40)];
        yield 'wrapped' => [new Bounds(-20, 170, 20, -170)];
        yield 'full world' => [new Bounds(-90, -180, 90, 180)];
        yield 'full longitude strip' => [new Bounds(0, -180, 10, 180)];
        yield 'antimeridian only reversed endpoints' => [new Bounds(0, 180, 10, -180)];
        yield 'touch negative antimeridian' => [new Bounds(0, -180, 10, -170)];
        yield 'touch positive antimeridian' => [new Bounds(0, 170, 10, 180)];
        yield 'zero width ordinary meridian' => [new Bounds(-20, 30, 20, 30)];
        yield 'zero height' => [new Bounds(0, -30, 0, 40)];
        yield 'single point' => [new Bounds(5, 30, 5, 30)];
        yield 'north pole with longitude interval' => [new Bounds(90, 170, 90, -170)];
        yield 'south pole with longitude interval' => [new Bounds(-90, -30, -90, 40)];
        yield 'unwrapped viewport' => [Bounds::fromViewport(-20, 175, 20, 185)];
        yield 'multiple world offset' => [Bounds::fromViewport(0, -540, 10, -530)];
        yield 'exactly one world' => [Bounds::fromViewport(0, 10, 10, 370)];
        yield 'more than one world' => [Bounds::fromViewport(-20, -540, 20, 200)];
    }

    #[DataProvider('signedMeridianViewports')]
    public function test_signed_antimeridian_semantics_have_explicit_expected_sql_results(Bounds $bounds, array $expected): void
    {
        foreach ([-180.0, -175.0, -170.0, 0.0, 170.0, 175.0, 180.0] as $longitude) {
            $this->branch((string) $longitude, 5, $longitude);
        }

        self::assertSame($expected, Branch::query()->withinBounds($bounds)->orderBy('id')->pluck('id')->all());
    }

    public static function signedMeridianViewports(): iterable
    {
        yield 'full longitude domain' => [new Bounds(0, -180, 10, 180), [1, 2, 3, 4, 5, 6, 7]];
        yield 'reversed endpoints are one meridian' => [new Bounds(0, 180, 10, -180), [1, 7]];
        yield 'equal negative endpoints' => [new Bounds(0, -180, 10, -180), [1, 7]];
        yield 'equal positive endpoints' => [new Bounds(0, 180, 10, 180), [1, 7]];
        yield 'negative endpoint equivalence' => [new Bounds(0, -180, 10, -170), [1, 2, 3, 7]];
        yield 'positive endpoint equivalence' => [new Bounds(0, 170, 10, 180), [1, 5, 6, 7]];
        yield 'wrapped interval' => [new Bounds(0, 170, 10, -170), [1, 2, 3, 5, 6, 7]];
    }

    public function test_edges_and_corners_are_inclusive_without_expanding_the_requested_viewport(): void
    {
        $bounds = new Bounds(35.6, 51.2, 35.8, 51.5);
        $inside = [];
        foreach ([[35.6, 51.2], [35.6, 51.5], [35.8, 51.2], [35.8, 51.5], [35.6, 51.35], [35.8, 51.35], [35.7, 51.2], [35.7, 51.5], [35.7, 51.35]] as [$latitude, $longitude]) {
            $inside[] = $this->branch('Included', $latitude, $longitude)->id;
        }
        foreach ([[35.6 - 1.0e-12, 51.35], [35.8 + 1.0e-12, 51.35], [35.7, 51.2 - 1.0e-12], [35.7, 51.5 + 1.0e-12]] as [$latitude, $longitude]) {
            $this->branch('Immediately outside', $latitude, $longitude);
        }

        self::assertSame($inside, Branch::query()->withinBounds($bounds)->orderBy('id')->pluck('id')->all());
    }

    public function test_viewport_only_queries_add_no_distance_order_limit_or_lookup_query(): void
    {
        $branch = $this->branch('Selected', 5, 175);
        $this->branch('Outside', 5, 0);
        $pdo = $this->observedPdo();
        DB::connection()->setPdo($pdo)->setReadPdo($pdo);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $query = Branch::query()->where('is_active', true)->withinBounds(new Bounds(0, 170, 10, -170));

        self::assertInstanceOf(Builder::class, $query);
        self::assertSame([], DB::getQueryLog());
        self::assertSame(0, $pdo->serverVersionReads);
        self::assertNull($query->getQuery()->orders);
        self::assertNull($query->getQuery()->limit);
        self::assertNull($query->getQuery()->offset);
        $sql = $query->toSql();
        self::assertDoesNotMatchRegularExpression('/(?:ATAN2|SIN|COS|RADIANS|SQRT|ABS|MOD)\s*\(/i', $sql);
        self::assertDoesNotMatchRegularExpression('/CAST\s*\(\s*`/i', $sql);
        self::assertStringNotContainsString('nearby_distance_meters', $sql);
        self::assertSame(substr_count($sql, '?'), count($query->getBindings()));
        self::assertSame([], DB::getQueryLog());
        $models = $query->get();

        self::assertSame([$branch->id], $models->modelKeys());
        self::assertNull($models[0]->nearbyDistance());
        self::assertArrayNotHasKey('nearby_distance_meters', $models[0]->toArray());
        self::assertCount(1, DB::getQueryLog());
        self::assertSame(1, $pdo->serverVersionReads);
        self::assertSame(1, $pdo->prepareAttempts);
        self::assertSame(1, $pdo->executeAttempts);
        DB::disableQueryLog();
    }

    public function test_explicit_selection_order_offset_and_limit_are_preserved(): void
    {
        $this->branch('A', 5, 175);
        $middle = $this->branch('B', 5, -175);
        $this->branch('C', 5, 180);
        $query = Branch::query()->select('id', 'name')->orderBy('name', 'desc')->offset(1)->limit(1)
            ->withinBounds(new Bounds(0, 170, 10, -170));
        $model = $query->get()->firstOrFail();

        self::assertSame($middle->id, $model->id);
        self::assertSame('B', $model->name);
        self::assertSame(['id', 'name'], array_keys($model->getAttributes()));
        self::assertSame(1, $query->getQuery()->limit);
        self::assertSame(1, $query->getQuery()->offset);
    }

    public function test_wrapped_longitude_alternatives_do_not_escape_prior_or_conditions_or_global_scopes(): void
    {
        $first = $this->branch('Active', 5, 175);
        $second = $this->branch('Allowed inactive', 5, -175, ['is_active' => false]);
        $this->branch('Inactive', 5, -175, ['is_active' => false]);
        $this->branch('Allowed inactive', 5, 0, ['is_active' => false]);
        $this->branch('Other tenant', 5, -175, ['tenant_id' => 2]);
        $this->branch('Deleted', 5, -175, ['deleted_at' => '2026-01-01 00:00:00']);
        $query = ScopedBranch::query()->where('is_active', true)->orWhere('name', 'Allowed inactive')
            ->withinBounds(new Bounds(0, 170, 10, -170))->orderBy('id');

        self::assertSame([$first->id, $second->id], $query->get()->modelKeys());
    }

    public function test_nested_or_viewports_remain_inside_the_application_filter(): void
    {
        $west = $this->branch('West', 0, -175);
        $east = $this->branch('East', 0, 175);
        $this->branch('Central', 0, 0);
        $this->branch('Inactive east', 0, 175, ['is_active' => false]);
        $query = Branch::query()->where('is_active', true)->where(static function (Builder $query): void {
            $query->where(static fn (Builder $query) => $query->withinBounds(new Bounds(-1, -180, 1, -170)))
                ->orWhere(static fn (Builder $query) => $query->withinBounds(new Bounds(-1, 170, 1, 180)));
        });

        self::assertSame([$west->id, $east->id], $query->orderBy('id')->get()->modelKeys());
    }

    public function test_repeated_viewports_intersect_and_allow_independent_builder_clones(): void
    {
        $east = $this->branch('East', 5, 178);
        $west = $this->branch('West', 5, -178);
        $this->branch('Only broad bounds', 5, 172);
        $this->branch('Outside both', 5, 0);
        $query = Branch::query()->withinBounds(new Bounds(0, 170, 10, -170));
        $narrowed = (clone $query)->withinBounds(new Bounds(0, 175, 10, -175));

        self::assertSame([$east->id, $west->id], $narrowed->orderBy('id')->get()->modelKeys());
        self::assertSame(3, $query->count());
        self::assertSame(2, (clone $narrowed)->count());
    }

    public function test_bounds_and_radius_filters_both_remain_active_in_either_scope_order(): void
    {
        $origin = new Coordinate(0, 179.9);
        $eligible = $this->branch('Inside both', 0, 179.95);
        $this->branch('Only radius', 0, 179.7);
        $this->branch('Only viewport', 0, -175);
        $bounds = new Bounds(-1, 179.8, 1, -170);
        $radius = Distance::fromKilometers(50);

        self::assertSame([$eligible->id], Branch::query()->withinBounds($bounds)->withinRadius($origin, $radius)->get()->modelKeys());
        self::assertSame([$eligible->id], Branch::query()->withinRadius($origin, $radius)->withinBounds($bounds)->get()->modelKeys());
    }

    public function test_bounds_compose_with_nearest_and_distance_hydration_without_reordering_the_viewport_scope(): void
    {
        $origin = new Coordinate(0, 0);
        $this->branch('Closest outside viewport', 0, 0);
        $second = $this->branch('Second inside', 0, 0.02);
        $first = $this->branch('First inside', 0, 0.01);
        $bounds = new Bounds(-1, 0.005, 1, 0.025);
        $results = Branch::query()->orderBy('name')->withinBounds($bounds)->nearestTo($origin, 1)->get();

        self::assertSame([$first->id], $results->modelKeys());
        self::assertEqualsWithDelta(Geo::distance($origin, new Coordinate(0, 0.01))->in(Unit::Meter), $results[0]->nearby_distance_meters, 1.0e-6);
        self::assertSame([$second->id, $first->id], Branch::query()->withDistance($origin)->orderByDistance($origin, 'desc')
            ->withinBounds($bounds)->get()->modelKeys());
    }

    public function test_custom_decimal_columns_keys_and_paginated_distance_results_are_preserved(): void
    {
        CustomBranch::create(['code' => 'east', 'name' => 'East', 'lat' => '5.000000000000', 'lng' => '175.000000000000']);
        CustomBranch::create(['code' => 'west', 'name' => 'West', 'lat' => '5.000000000000', 'lng' => '-175.000000000000']);
        CustomBranch::create(['code' => 'outside', 'name' => 'Outside', 'lat' => '5.000000000000', 'lng' => '0.000000000000']);
        $query = CustomBranch::query()->withinBounds(new Bounds(0, 170, 10, -170));

        self::assertSame(2, (clone $query)->count());
        self::assertTrue((clone $query)->exists());
        $page = $query->withDistance(new Coordinate(5, 180))->orderBy('code')->paginate(1, ['*'], 'page', 2);
        self::assertSame(2, $page->total());
        self::assertSame('west', $page->items()[0]->code);
        self::assertIsFloat($page->items()[0]->nearby_distance_meters);
    }

    #[DataProvider('highScaleDecimalViewports')]
    public function test_high_scale_decimal_values_outside_the_valid_coordinate_domain_are_excluded(Bounds $bounds): void
    {
        DB::statement('ALTER TABLE nearby_test_custom_branches MODIFY lat DECIMAL(30,20) NULL, MODIFY lng DECIMAL(30,20) NULL');
        foreach ([
            ['east', '0', '180'],
            ['west', '0', '-180'],
            ['north', '90', '180'],
            ['south', '-90', '-180'],
            ['invalid-latitude-high', '90.00000000000000000001', '180'],
            ['invalid-latitude-low', '-90.00000000000000000001', '-180'],
            ['invalid-longitude-high', '0', '180.00000000000000000001'],
            ['invalid-longitude-low', '0', '-180.00000000000000000001'],
        ] as [$code, $latitude, $longitude]) {
            CustomBranch::create(['code' => $code, 'name' => $code, 'lat' => $latitude, 'lng' => $longitude]);
        }

        self::assertSame(
            ['east', 'north', 'south', 'west'],
            CustomBranch::query()->withinBounds($bounds)->orderBy('code')->pluck('code')->all(),
        );
    }

    public static function highScaleDecimalViewports(): iterable
    {
        yield 'full world' => [new Bounds(-90, -180, 90, 180)];
        yield 'wrapped' => [new Bounds(-90, 170, 90, -170)];
        yield 'negative antimeridian endpoint' => [new Bounds(-90, -180, 90, -170)];
        yield 'positive antimeridian endpoint' => [new Bounds(-90, 170, 90, 180)];
    }

    public function test_joined_viewport_queries_qualify_coordinates_and_keep_only_the_model_selection(): void
    {
        $branch = $this->branch('Joined', 5, 175);
        $this->branch('Outside', 5, 0);
        $result = Branch::query()->join('nearby_test_branches as peer', 'peer.id', '=', 'nearby_test_branches.id')
            ->where('peer.name', 'Joined')->withinBounds(new Bounds(0, 170, 10, -170))->firstOrFail();

        self::assertSame($branch->id, $result->id);
        self::assertSame('Joined', $result->name);
        self::assertNull($result->nearbyDistance());
    }

    public function test_select_where_order_and_viewport_bindings_keep_their_separate_positions(): void
    {
        $branch = $this->branch('Bound row', 5, 175);
        $bounds = new Bounds(1.23456789, 170.123456789, 9.87654321, -170.987654321);
        $query = Branch::query()->select('id', 'name')->selectRaw('? AS requested_label', ['Label'])
            ->where('name', 'Bound row')->orderByRaw('name = ? DESC', ['Bound row'])->withinBounds($bounds);
        $sql = $query->toSql();

        foreach (['1.23456789', '170.123456789', '9.87654321', '-170.987654321'] as $number) {
            self::assertStringNotContainsString($number, $sql);
        }
        self::assertSame(substr_count($sql, '?'), count($query->getBindings()));
        $model = $query->firstOrFail();
        self::assertSame($branch->id, $model->id);
        self::assertSame('Label', $model->requested_label);
    }

    #[DataProvider('unsafeColumns')]
    public function test_unsafe_coordinate_identifier_configuration_is_rejected(string $column, bool $latitude): void
    {
        if ($latitude) {
            UnsafeBranch::$latitudeColumn = $column;
        } else {
            UnsafeBranch::$longitudeColumn = $column;
        }
        $this->expectException(InvalidArgumentException::class);

        UnsafeBranch::query()->withinBounds(new Bounds(-1, -1, 1, 1))->get();
    }

    public static function unsafeColumns(): iterable
    {
        yield 'latitude SQL fragment' => ['latitude); DROP TABLE nearby_test_branches; --', true];
        yield 'longitude SQL fragment' => ['longitude OR 1=1', false];
        yield 'qualified latitude' => ['other.latitude', true];
        yield 'empty longitude' => ['', false];
    }

    public function test_custom_query_builders_fail_closed_before_any_statement_preparation(): void
    {
        $pdo = $this->observedPdo();
        DB::connection()->setPdo($pdo)->setReadPdo($pdo);
        $this->expectException(UnsupportedDatabaseException::class);

        try {
            CustomBuilderBranch::query()->withinBounds(new Bounds(-1, -1, 1, 1))->get();
        } finally {
            self::assertSame(0, $pdo->prepareAttempts);
            self::assertSame(0, $pdo->executeAttempts);
        }
    }

    #[DataProvider('unsupportedDrivers')]
    public function test_unsupported_drivers_are_rejected_without_connecting(string $driver): void
    {
        $name = 'nearby_bounds_'.$driver;
        $config = config('database.connections.nearby_test_mysql');
        $config['driver'] = $driver;
        if ($driver === 'sqlite') {
            $config['database'] = ':memory:';
        }
        config()->set('database.connections.'.$name, $config);
        $connection = DB::connection($name);
        $this->expectException(UnsupportedDatabaseException::class);

        try {
            Branch::on($name)->withinBounds(new Bounds(-1, -1, 1, 1))->get();
        } finally {
            self::assertInstanceOf(\Closure::class, $connection->getRawPdo());
        }
    }

    public static function unsupportedDrivers(): iterable
    {
        yield 'SQLite' => ['sqlite'];
        yield 'PostgreSQL' => ['pgsql'];
        yield 'MariaDB' => ['mariadb'];
    }

    #[DataProvider('unsupportedVersions')]
    public function test_viewport_only_queries_validate_the_actual_server_before_preparing_sql(string $version): void
    {
        $pdo = $this->observedPdo();
        // This is an actual MySQL 8.4 PDO with only its reported version substituted.
        $pdo->reportedVersion = $version;
        DB::connection()->setPdo($pdo)->setReadPdo($pdo);
        $query = Branch::query()->withinBounds(new Bounds(-1, -1, 1, 1));
        self::assertSame(0, $pdo->serverVersionReads);
        $this->expectException(UnsupportedDatabaseException::class);

        try {
            $query->get();
        } finally {
            self::assertSame(1, $pdo->serverVersionReads);
            self::assertSame(0, $pdo->prepareAttempts);
            self::assertSame(0, $pdo->executeAttempts);
        }
    }

    public static function unsupportedVersions(): iterable
    {
        yield 'older MySQL' => ['8.0.42'];
        yield 'MariaDB version' => ['11.4.3-MariaDB'];
    }

    #[DataProvider('unsupportedVersions')]
    public function test_nested_bounds_only_queries_reject_unsupported_in_flight_replacements_before_sql(string $version): void
    {
        $this->branch('Eligible', 0, 0.01);
        $connection = DB::connection();
        $initial = $this->observedPdo();
        $connection->setPdo($initial)->setReadPdo($initial);
        $query = Branch::query()->where(static function (Builder $query): void {
            $query->withinBounds(new Bounds(-1, -1, 1, 1));
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
            self::assertInstanceOf(BoundsObservedPdo::class, $replacement);
            self::assertSame(1, $replacement->serverVersionReads);
            self::assertSame(['version'], $replacement->events);
            self::assertSame(0, $replacement->prepareAttempts);
            self::assertSame(0, $replacement->executeAttempts);
        }
    }

    #[DataProvider('retryMethods')]
    public function test_supported_lost_session_replacements_are_checked_before_viewport_statement_preparation(string $method, bool $write): void
    {
        $branch = $this->branch('Eligible', 0, 0.01);
        $connection = DB::connection();
        $initial = $this->observedPdo();
        $connection->setPdo($initial)->setReadPdo($initial);
        $query = Branch::query()->withinBounds(new Bounds(-1, -1, 1, 1));
        if ($write) {
            $query->useWritePdo();
        }
        self::assertCount(1, $query->get());
        $replacement = null;
        $connection->setReconnector(function (Connection $connection) use (&$replacement): Connection {
            $replacement = $this->observedPdo();

            return $connection->setPdo($replacement)->setReadPdo($replacement);
        });
        $this->terminateOwnSession($initial);
        $result = $this->retrieve(clone $query, $method);

        if ($method === 'count') {
            self::assertSame(1, $result);
        } elseif ($method === 'exists') {
            self::assertTrue($result);
        } else {
            self::assertSame([$branch->id], $result->pluck('id')->all());
            self::assertNull($result->first()->nearbyDistance());
        }
        self::assertInstanceOf(BoundsObservedPdo::class, $replacement);
        self::assertSame(['version', 'prepare', 'execute'], $replacement->events);
        self::assertSame(1, $replacement->serverVersionReads);
    }

    #[DataProvider('unsupportedRetries')]
    public function test_unsupported_lost_session_replacements_cannot_prepare_or_execute_viewport_sql(string $method, bool $write, string $version): void
    {
        $this->branch('Eligible', 0, 0.01);
        $connection = DB::connection();
        $initial = $this->observedPdo();
        $connection->setPdo($initial)->setReadPdo($initial);
        $query = Branch::query()->withinBounds(new Bounds(-1, -1, 1, 1));
        if ($write) {
            $query->useWritePdo();
        }
        $query->get();
        $replacement = null;
        $connection->setReconnector(function (Connection $connection) use (&$replacement, $version): Connection {
            $replacement = $this->observedPdo();
            $replacement->reportedVersion = $version;

            return $connection->setPdo($replacement)->setReadPdo($replacement);
        });
        $this->terminateOwnSession($initial);
        $this->expectException(UnsupportedDatabaseException::class);

        try {
            $this->retrieve(clone $query, $method);
        } finally {
            self::assertInstanceOf(BoundsObservedPdo::class, $replacement);
            self::assertSame(['version'], $replacement->events);
            self::assertSame(0, $replacement->prepareAttempts);
            self::assertSame(0, $replacement->executeAttempts);
        }
    }

    public static function retryMethods(): iterable
    {
        yield 'get with read PDO' => ['get', false];
        yield 'count with write PDO' => ['count', true];
        yield 'exists with read PDO' => ['exists', false];
        yield 'cursor with write PDO' => ['cursor', true];
    }

    public static function unsupportedRetries(): iterable
    {
        foreach (self::retryMethods() as $name => [$method, $write]) {
            foreach (['8.0.42', '11.4.3-MariaDB'] as $version) {
                yield $name.' '.$version => [$method, $write, $version];
            }
        }
    }

    /** @return array<int, Coordinate> */
    private function seedCoordinates(Bounds $bounds): array
    {
        $points = [];
        foreach ([-90.0, -20.0, 0.0, 5.0, 10.0, 20.0, 90.0] as $latitude) {
            foreach ([-180.0, -179.0, -175.0, -170.0, 0.0, 30.0, 170.0, 175.0, 179.0, 180.0] as $longitude) {
                $points[] = [$latitude, $longitude];
            }
        }
        foreach ([$bounds->south, $bounds->north] as $latitude) {
            foreach ([$bounds->west, $bounds->east] as $longitude) {
                $points[] = [$latitude, $longitude];
            }
        }
        $random = new Randomizer(new Mt19937(20261102));
        for ($index = 0; $index < 32; $index++) {
            $points[] = [$random->getInt(-90000000, 90000000) / 1000000, $random->getInt(-180000000, 180000000) / 1000000];
        }
        $rows = [];
        $coordinates = [];
        foreach ($points as $index => [$latitude, $longitude]) {
            $id = $index + 1;
            $coordinates[$id] = new Coordinate($latitude, $longitude);
            $rows[] = ['id' => $id, 'name' => 'Point '.$id, 'latitude' => sprintf('%.17g', $latitude), 'longitude' => sprintf('%.17g', $longitude)];
        }
        foreach ([[null, 0.0], [0.0, null], [null, null], [-90.000001, 0.0], [90.000001, 0.0], [0.0, -180.000001], [0.0, 500.0]] as [$latitude, $longitude]) {
            $rows[] = ['id' => count($rows) + 1, 'name' => 'Invalid', 'latitude' => $latitude, 'longitude' => $longitude];
        }
        DB::table('nearby_test_branches')->insert($rows);

        return $coordinates;
    }

    private function observedPdo(): BoundsObservedPdo
    {
        $config = config('database.connections.nearby_test_mysql');
        $pdo = new BoundsObservedPdo(
            'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4',
            $config['username'],
            $config['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false],
        );
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [BoundsObservedStatement::class, [$pdo]]);

        return $pdo;
    }

    private function terminateOwnSession(BoundsObservedPdo $pdo): void
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
}
