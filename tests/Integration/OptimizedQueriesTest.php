<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Zarbinco\Nearby\Calculators\HaversineCalculator;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Eloquent\MySqlDistanceExpression;
use Zarbinco\Nearby\Geo;
use Zarbinco\Nearby\Tests\Integration\Fixtures\Branch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\CustomBranch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\ScopedBranch;
use Zarbinco\Nearby\Unit;

final class OptimizedQueriesTest extends MySqlTestCase
{
    #[DataProvider('boundedSearches')]
    public function test_bounded_results_match_the_original_exact_sql_and_php_reference(Coordinate $origin, float $meters): void
    {
        $coordinates = $this->seedCoordinates($origin);
        $radius = Distance::fromMeters($meters);
        $baseline = $this->exactWithin($origin, $radius)->orderBy('id')->pluck('id')->all();
        $optimized = Branch::query()->withinRadius($origin, $radius)->orderBy('id');

        self::assertSame($baseline, (clone $optimized)->pluck('id')->all());
        self::assertSame(count($baseline), (clone $optimized)->count());
        self::assertSame($baseline !== [], (clone $optimized)->exists());

        $sqlDistances = Branch::query()->withDistance($origin)->get()->keyBy('id');
        $halfCircumference = M_PI * HaversineCalculator::MEAN_EARTH_RADIUS_METERS;
        foreach ($coordinates as $id => $coordinate) {
            $phpMeters = Geo::distance($origin, $coordinate)->in(Unit::Meter);
            $tolerance = 1.0e-6;
            if ($halfCircumference - $phpMeters < 1000.0) {
                $tolerance = $origin->latitude === 0.0 && $coordinate->latitude === 0.0 ? 0.001 : 0.5;
            }

            self::assertEqualsWithDelta($phpMeters, $sqlDistances[$id]->nearby_distance_meters, $tolerance);

            // PHP and MySQL can straddle an exact cutoff within their floating-point tolerance.
            if (abs($phpMeters - $meters) > $tolerance) {
                self::assertSame($phpMeters <= $meters, in_array($id, $baseline, true));
            }
        }

        $minimum = Distance::fromMeters($meters / 3.0);
        $exactBand = Branch::query();
        $expression = new MySqlDistanceExpression($exactBand, $origin, 'latitude', 'longitude');
        $exactBand->whereRaw($expression->sql.' BETWEEN CAST(? AS DOUBLE) AND CAST(? AS DOUBLE)', [
            ...$expression->bindings,
            MySqlDistanceExpression::bindNumber($minimum->in(Unit::Meter)),
            MySqlDistanceExpression::bindNumber($meters),
        ]);

        self::assertSame(
            $exactBand->orderBy('id')->pluck('id')->all(),
            Branch::query()->betweenDistances($origin, $minimum, $radius)->orderBy('id')->pluck('id')->all(),
        );
    }

    public static function boundedSearches(): iterable
    {
        $halfCircumference = M_PI * HaversineCalculator::MEAN_EARTH_RADIUS_METERS;

        yield 'ordinary zero radius' => [new Coordinate(35, 51), 0.0];
        yield 'antimeridian zero radius' => [new Coordinate(0, 180), 0.0];
        yield 'north pole zero radius' => [new Coordinate(90, -45), 0.0];
        yield 'south pole zero radius' => [new Coordinate(-90, 150), 0.0];
        yield 'micrometer radius' => [new Coordinate(35, 51), 1.0e-6];
        yield 'tiny antimeridian radius' => [new Coordinate(0, 179.999999999), 0.001];
        yield 'nanometer polar radius' => [new Coordinate(89.99999999999999, 0), 4.0e-9];
        yield 'ordinary local search' => [new Coordinate(35.6892, 51.389), 5000.0];
        yield 'east antimeridian wrapping' => [new Coordinate(0, 179.9), 50000.0];
        yield 'west antimeridian wrapping' => [new Coordinate(0, -179.9), 50000.0];
        yield 'north pole origin' => [new Coordinate(90, 50), 500000.0];
        yield 'south pole origin' => [new Coordinate(-90, -50), 500000.0];
        yield 'cap touches north pole' => [new Coordinate(80, 45), HaversineCalculator::MEAN_EARTH_RADIUS_METERS * M_PI / 18.0];
        yield 'cap contains north pole' => [new Coordinate(80, 45), 1500000.0];
        yield 'cap touches south pole' => [new Coordinate(-80, -45), HaversineCalculator::MEAN_EARTH_RADIUS_METERS * M_PI / 18.0];
        yield 'larger than hemisphere' => [new Coordinate(-25, 10), 15000000.0];
        yield 'almost full globe' => [new Coordinate(0, 0), $halfCircumference - 0.05];
        yield 'full globe' => [new Coordinate(0, 0), $halfCircumference];
        yield 'radius above maximum geographic distance' => [new Coordinate(0, 0), PHP_FLOAT_MAX];
    }

    public function test_every_selected_point_remains_available_at_its_exact_database_radius(): void
    {
        $origin = new Coordinate(63.25, 179.75);
        $this->seedCoordinates($origin);
        $selected = Branch::query()->withDistance($origin)->get();

        foreach ($selected as $branch) {
            if ($branch->nearby_distance_meters === null) {
                continue;
            }

            $radius = Distance::fromMeters($branch->nearby_distance_meters);
            self::assertTrue(Branch::query()->whereKey($branch->id)->withinRadius($origin, $radius)->exists(), $branch->name);
            self::assertTrue(Branch::query()->whereKey($branch->id)->betweenDistances($origin, $radius, $radius)->exists(), $branch->name);
        }
    }

    public function test_wrapped_box_conditions_preserve_or_groups_global_scopes_and_soft_deletes(): void
    {
        $west = $this->branch('West active', 0, 179.8);
        $east = $this->branch('East active', 0, -179.8);
        $manual = $this->branch('Manual', 0, -179.9, ['is_active' => false]);
        $this->branch('Manual', 0, 0, ['is_active' => false]);
        $this->branch('Inactive', 0, -179.9, ['is_active' => false]);
        $this->branch('Other tenant', 0, -179.9, ['tenant_id' => 2]);
        $this->branch('Deleted', 0, 179.9, ['deleted_at' => '2026-01-01 00:00:00']);
        $origin = new Coordinate(0, 179.9);
        $results = ScopedBranch::query()->where('is_active', true)->orWhere('name', 'Manual')
            ->withinRadius($origin, Distance::fromKilometers(50))->orderBy('id')->get();

        self::assertSame([$west->id, $east->id, $manual->id], $results->modelKeys());
    }

    public function test_bounded_join_queries_keep_coordinate_identifiers_qualified(): void
    {
        $matching = $this->branch('Matching', 0, 179.9);
        $this->branch('Far', 0, 0);
        $results = Branch::query()->join('nearby_test_branches as peer', 'peer.id', '=', 'nearby_test_branches.id')
            ->where('peer.is_active', true)->withinRadius(new Coordinate(0, -179.9), Distance::fromKilometers(50))
            ->nearestTo(new Coordinate(0, -179.9))->get();

        self::assertSame([$matching->id], $results->modelKeys());
        self::assertIsFloat($results[0]->nearby_distance_meters);
    }

    public function test_bounded_custom_decimal_columns_preserve_key_order_aggregates_and_pagination(): void
    {
        CustomBranch::create(['code' => 'z-west', 'name' => 'West', 'lat' => '0', 'lng' => '179.9']);
        CustomBranch::create(['code' => 'a-east', 'name' => 'East', 'lat' => '0', 'lng' => '-179.9']);
        CustomBranch::create(['code' => 'far', 'name' => 'Far', 'lat' => '0', 'lng' => '0']);
        $origin = new Coordinate(0, 180);
        $query = CustomBranch::query()->betweenDistances($origin, Distance::fromKilometers(1), Distance::fromKilometers(50));

        self::assertSame(2, (clone $query)->count());
        self::assertTrue((clone $query)->exists());
        self::assertSame(['a-east', 'z-west'], (clone $query)->nearestTo($origin)->get()->modelKeys());
        $page = $query->withDistance($origin)->orderByDistance($origin)->paginate(1, ['*'], 'page', 2);
        self::assertSame(2, $page->total());
        self::assertSame('z-west', $page->items()[0]->code);
        self::assertIsFloat($page->items()[0]->nearby_distance_meters);
    }

    public function test_bounded_nearest_results_match_exact_sql_order_and_limit(): void
    {
        $origin = new Coordinate(35.6892, 51.389);
        $this->seedCoordinates($origin);
        $radius = Distance::fromKilometers(50);
        $baseline = $this->exactWithin($origin, $radius)->orderByDistance($origin)->limit(7)->get();
        $optimized = Branch::query()->withinRadius($origin, $radius)->nearestTo($origin, 7)->get();

        self::assertSame($baseline->modelKeys(), $optimized->modelKeys());
    }

    public function test_box_corner_candidates_are_still_removed_by_the_exact_circle(): void
    {
        $inside = $this->branch('Inside circle', 0.02, 0.02);
        $this->branch('Inside box but outside circle', 0.04, 0.04);

        self::assertSame(
            [$inside->id],
            Branch::query()->withinRadius(new Coordinate(0, 0), Distance::fromKilometers(5))->get()->modelKeys(),
        );
    }

    private function exactWithin(Coordinate $origin, Distance $radius): Builder
    {
        $query = Branch::query();
        $expression = new MySqlDistanceExpression($query, $origin, 'latitude', 'longitude');

        return $query->whereRaw($expression->sql.' <= CAST(? AS DOUBLE)', [
            ...$expression->bindings,
            MySqlDistanceExpression::bindNumber($radius->in(Unit::Meter)),
        ]);
    }

    /** @return array<int, Coordinate> */
    private function seedCoordinates(Coordinate $origin): array
    {
        $points = [
            $origin,
            $origin,
            new Coordinate(0, 0),
            new Coordinate(0, 180),
            new Coordinate(0, -180),
            new Coordinate(90, 0),
            new Coordinate(90, 180),
            new Coordinate(-90, -45),
            new Coordinate(-90, 120),
            new Coordinate(89.99999999999999, 180),
            new Coordinate(-89.99999999999999, -180),
            new Coordinate(0, 179.999999999),
            new Coordinate(0, -179.999999999),
            new Coordinate(-$origin->latitude, $origin->longitude > 0 ? $origin->longitude - 180 : $origin->longitude + 180),
        ];
        $random = new Randomizer(new Mt19937(20261101));

        for ($index = 0; $index < 96; $index++) {
            $points[] = new Coordinate($random->getInt(-90000000, 90000000) / 1000000, $random->getInt(-180000000, 180000000) / 1000000);
        }

        foreach ([1.0e-12, 1.0e-8, 0.001, 0.01, 0.1, 1.0, 10.0] as $offset) {
            foreach ([-1.0, 1.0] as $sign) {
                $latitude = max(-90.0, min(90.0, $origin->latitude + $offset * $sign));
                $longitude = $origin->longitude + $offset * $sign;
                if ($longitude > 180.0) {
                    $longitude -= 360.0;
                } elseif ($longitude < -180.0) {
                    $longitude += 360.0;
                }
                $points[] = new Coordinate($latitude, $origin->longitude);
                $points[] = new Coordinate($origin->latitude, $longitude);
            }
        }

        $rows = [];
        $coordinates = [];
        foreach ($points as $index => $coordinate) {
            $id = $index + 1;
            $coordinates[$id] = $coordinate;
            $rows[] = [
                'id' => $id,
                'name' => 'Point '.$id,
                'latitude' => sprintf('%.17g', $coordinate->latitude),
                'longitude' => sprintf('%.17g', $coordinate->longitude),
            ];
        }
        $rows[] = ['id' => count($rows) + 1, 'name' => 'Missing', 'latitude' => null, 'longitude' => null];
        $rows[] = ['id' => count($rows) + 1, 'name' => 'Invalid', 'latitude' => '91', 'longitude' => '181'];
        DB::table('nearby_test_branches')->insert($rows);

        // Exercise the stored antimeridian endpoint separately from Coordinate canonicalization.
        $antimeridianId = $this->branch('Stored negative antimeridian', 0, -180)->id;
        $coordinates[$antimeridianId] = new Coordinate(0, -180);

        return $coordinates;
    }
}
