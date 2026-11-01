<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Geo;
use Zarbinco\Nearby\Tests\Integration\Fixtures\Branch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\CustomBranch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\ScopedBranch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\UnsafeBranch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\WeakLimitCaller;
use Zarbinco\Nearby\Unit;

final class NearbyQueriesTest extends MySqlTestCase
{
    public function test_with_distance_retains_models_and_exposes_a_float_and_distance_value(): void
    {
        $branch = $this->branch('Karaj', 35.8400, 50.9391);
        $origin = new Coordinate(35.6892, 51.3890);
        $result = Branch::query()->whereKey($branch->id)->withDistance($origin)->firstOrFail();

        self::assertInstanceOf(Branch::class, $result);
        self::assertSame('Karaj', $result->name);
        self::assertIsFloat($result->nearby_distance_meters);
        self::assertEqualsWithDelta(
            Geo::distance($origin, new Coordinate(35.8400, 50.9391))->in(Unit::Meter),
            $result->nearby_distance_meters,
            1.0e-6,
        );
        self::assertInstanceOf(Distance::class, $result->nearbyDistance());
        self::assertSame($result->nearby_distance_meters, $result->nearbyDistance()->in(Unit::Meter));

        foreach (Unit::cases() as $unit) {
            self::assertSame(
                Distance::fromMeters($result->nearby_distance_meters)->in($unit),
                $result->nearbyDistance()->in($unit),
            );
        }
    }

    public function test_models_without_a_selected_distance_return_null(): void
    {
        $this->branch('Ordinary', 0, 1);
        $result = Branch::query()->firstOrFail();

        self::assertNull($result->nearby_distance_meters);
        self::assertNull($result->nearbyDistance());
    }

    public function test_with_distance_preserves_explicit_selected_attributes_and_filters(): void
    {
        $this->branch('Selected', 0, 1);
        $this->branch('Excluded', 0, 2);
        $result = Branch::query()->select('id', 'name')->where('name', 'Selected')
            ->withDistance(new Coordinate(0, 0))->firstOrFail();

        self::assertSame('Selected', $result->name);
        self::assertArrayNotHasKey('latitude', $result->getAttributes());
        self::assertIsFloat($result->nearby_distance_meters);
    }

    public function test_select_after_with_distance_rejects_a_removed_calculation(): void
    {
        $this->branch('Selected', 0, 1);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Call select() before withDistance()');

        Branch::query()->withDistance(new Coordinate(0, 0))->select('id')->get();
    }

    public function test_add_select_after_with_distance_preserves_the_calculation(): void
    {
        $this->branch('Selected', 0, 1);
        $result = Branch::query()->select('id')->withDistance(new Coordinate(0, 0))
            ->addSelect('name')->firstOrFail();

        self::assertSame('Selected', $result->name);
        self::assertIsFloat($result->nearby_distance_meters);
        self::assertArrayNotHasKey('latitude', $result->getAttributes());
    }

    public function test_computed_distance_is_serialized_without_becoming_a_persisted_attribute(): void
    {
        $this->branch('Valid', 0, 1);
        $this->branch('Missing', null, 0);
        $results = Branch::query()->withDistance(new Coordinate(0, 0))->orderBy('id')->get();

        self::assertIsFloat($results[0]->toArray()['nearby_distance_meters']);
        self::assertNull($results[1]->toArray()['nearby_distance_meters']);

        foreach ($results as $result) {
            self::assertArrayNotHasKey('nearby_distance_meters', $result->getAttributes());
            self::assertArrayNotHasKey('nearby_distance_meters', $result->getDirty());
            self::assertSame([], $result->getDirty());
            foreach (array_keys($result->getAttributes()) as $attribute) {
                self::assertStringStartsNotWith('__nearby_', $attribute);
            }
        }
    }

    public function test_computed_distance_cannot_be_assigned_as_a_model_attribute(): void
    {
        $this->branch('Selected', 0, 1);
        $result = Branch::query()->withDistance(new Coordinate(0, 0))->firstOrFail();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('read-only');

        $result->nearby_distance_meters = 10.0;
    }

    public function test_with_distance_does_not_leak_select_bindings_into_aggregate_queries(): void
    {
        $this->branch('Active', 0, 1);
        $this->branch('Inactive', 0, 2, ['is_active' => false]);
        $this->branch('Active missing', null, null);
        $query = Branch::query()->where('is_active', true)->withDistance(new Coordinate(12.25, 45.75));

        self::assertSame(2, (clone $query)->count());
        self::assertTrue((clone $query)->exists());
        self::assertFalse((clone $query)->where('name', 'Absent')->exists());
    }

    public function test_with_distance_supports_scalar_plucks_without_treating_values_as_models(): void
    {
        $first = $this->branch('First', 0, 0);
        $second = $this->branch('Second', 0, 1);
        $origin = new Coordinate(0, 0);

        self::assertSame(
            [$first->id, $second->id],
            Branch::query()->withDistance($origin)->orderBy('id')->pluck('id')->all(),
        );

        $distances = Branch::query()->withDistance($origin)->orderBy('id')->get()->pluck('nearby_distance_meters');

        self::assertSame(0.0, $distances[0]);
        self::assertIsFloat($distances[1]);
        self::assertEqualsWithDelta(111195.0802335329, $distances[1], 1.0e-7);
    }

    public function test_nearest_sorts_and_limits_in_sql_with_deterministic_ties(): void
    {
        $this->branch('Far', 0, 20);
        $firstTie = $this->branch('First tie', 0, 1);
        $secondTie = $this->branch('Second tie', 0, -1);
        $nearest = $this->branch('Nearest', 0, 0.1);
        $this->branch('Missing', null, null);
        $query = Branch::query()->nearestTo(new Coordinate(0, 0), limit: 3);

        self::assertInstanceOf(Builder::class, $query);
        self::assertStringContainsString('limit 3', strtolower($query->toSql()));
        $results = $query->get();
        self::assertSame([$nearest->id, $firstTie->id, $secondTie->id], $results->modelKeys());
        self::assertIsFloat($results[0]->nearby_distance_meters);
    }

    public function test_nearest_removes_prior_ordering_and_preserves_where_conditions(): void
    {
        $this->branch('A far', 0, 5);
        $near = $this->branch('Z near', 0, 0.1);
        $this->branch('Inactive closest', 0, 0, ['is_active' => false]);

        $result = Branch::query()->where('is_active', true)->orderBy('name')
            ->nearestTo(new Coordinate(0, 0), 1)->firstOrFail();

        self::assertSame($near->id, $result->id);
    }

    public function test_nearest_clears_prior_order_bindings(): void
    {
        $far = $this->branch('Far', 0, 3);
        $near = $this->branch('Near', 0, 1);
        $result = Branch::query()->orderByRaw('name = ? DESC', ['Far'])
            ->nearestTo(new Coordinate(0, 0), 2)->get();

        self::assertSame([$near->id, $far->id], $result->modelKeys());
    }

    public function test_nearest_has_no_hidden_radius_and_returns_fewer_matches_when_appropriate(): void
    {
        $far = $this->branch('Across Earth', 0, 180);

        self::assertSame([$far->id], Branch::query()->nearestTo(new Coordinate(0, 0), 10)->get()->modelKeys());
        self::assertCount(0, Branch::query()->where('name', 'Missing')->nearestTo(new Coordinate(0, 0))->get());
    }

    public function test_distance_ordering_supports_both_directions_and_keeps_ties_stable(): void
    {
        $near = $this->branch('Near', 0, 0.1);
        $first = $this->branch('First equal', 0, 1);
        $second = $this->branch('Second equal', 0, -1);
        $this->branch('Invalid', 91, 0);
        $origin = new Coordinate(0, 0);

        self::assertSame([$near->id, $first->id, $second->id], Branch::query()->orderByDistance($origin)->get()->modelKeys());
        self::assertSame([$first->id, $second->id, $near->id], Branch::query()->orderByDistance($origin, direction: 'desc')->get()->modelKeys());
    }

    public function test_distance_ordering_appends_to_existing_ordering(): void
    {
        $aFar = $this->branch('A', 0, 3);
        $aNear = $this->branch('A', 0, 1);
        $bNear = $this->branch('B', 0, 0);

        self::assertSame(
            [$aNear->id, $aFar->id, $bNear->id],
            Branch::query()->orderBy('name')->orderByDistance(new Coordinate(0, 0))->get()->modelKeys(),
        );
    }

    public function test_radius_filtering_preserves_where_and_supports_aggregates_and_first(): void
    {
        $near = $this->branch('Near', 0, 0.01);
        $this->branch('Far', 0, 1);
        $this->branch('Inactive', 0, 0, ['is_active' => false]);
        $query = Branch::query()->where('is_active', true)
            ->withinRadius(new Coordinate(0, 0), Distance::fromKilometers(5));

        self::assertSame(1, (clone $query)->count());
        self::assertTrue((clone $query)->exists());
        self::assertSame($near->id, (clone $query)->firstOrFail()->id);
        self::assertSame([$near->id], (clone $query)->get()->modelKeys());
    }

    public function test_radius_boundary_is_inclusive_without_widening(): void
    {
        $boundary = $this->branch('Boundary', 0, 0.01);
        $this->branch('Just outside', 0, 0.010000000001);
        $origin = new Coordinate(0, 0);
        $meters = Branch::query()->whereKey($boundary->id)->withDistance($origin)->firstOrFail()->nearby_distance_meters;

        self::assertSame(
            [$boundary->id],
            Branch::query()->withinRadius($origin, Distance::fromMeters($meters))->get()->modelKeys(),
        );
    }

    public function test_radius_and_nearest_compose_without_expanding_the_radius(): void
    {
        $closest = $this->branch('Closest', 0, 0.001);
        $next = $this->branch('Next', 0, 0.01);
        $this->branch('Outside', 0, 1);
        $this->branch('Inactive', 0, 0, ['is_active' => false]);
        $origin = new Coordinate(0, 0);

        self::assertSame(
            [$closest->id, $next->id],
            Branch::query()->where('is_active', true)->withinRadius($origin, Distance::fromKilometers(5))
                ->nearestTo($origin, 10)->get()->modelKeys(),
        );
    }

    public function test_distance_bands_include_both_boundaries_and_allow_equal_values(): void
    {
        $near = $this->branch('Near', 0, 0.01);
        $far = $this->branch('Far', 0, 0.02);
        $this->branch('Outside', 0, 0.03);
        $origin = new Coordinate(0, 0);
        $distances = Branch::query()->withDistance($origin)->get()->keyBy('id');
        $min = Distance::fromMeters($distances[$near->id]->nearby_distance_meters);
        $max = Distance::fromMeters($distances[$far->id]->nearby_distance_meters);

        self::assertSame([$near->id, $far->id], Branch::query()->betweenDistances($origin, $min, $max)
            ->orderByDistance($origin)->get()->modelKeys());
        self::assertSame([$near->id], Branch::query()->betweenDistances($origin, $min, $min)->get()->modelKeys());
    }

    public function test_distance_band_supports_zero_aggregate_queries_and_pagination(): void
    {
        $this->branch('Coincident', 0, 0);
        $this->branch('Near', 0, 0.01);
        $this->branch('Outside', 0, 1);
        $query = Branch::query()->where('is_active', true)->betweenDistances(
            new Coordinate(0, 0), Distance::fromMeters(0), Distance::fromKilometers(5),
        );

        self::assertSame(2, (clone $query)->count());
        self::assertTrue((clone $query)->exists());
        $page = $query->orderBy('id')->paginate(1, ['*'], 'page', 2);
        self::assertSame(2, $page->total());
        self::assertSame('Near', $page->items()[0]->name);
    }

    public function test_within_radius_pagination_counts_and_selects_the_same_eligible_rows(): void
    {
        $this->branch('First', 0, 0);
        $this->branch('Second', 0, 0.01);
        $this->branch('Outside', 0, 1);
        $origin = new Coordinate(0, 0);
        $page = Branch::query()->where('is_active', true)->withinRadius($origin, Distance::fromKilometers(5))
            ->withDistance($origin)->orderByDistance($origin)->paginate(1, ['*'], 'page', 2);

        self::assertSame(2, $page->total());
        self::assertSame('Second', $page->items()[0]->name);
        self::assertIsFloat($page->items()[0]->nearby_distance_meters);
    }

    public function test_missing_and_invalid_coordinates_have_null_distances_and_are_excluded_from_nearby_results(): void
    {
        $valid = $this->branch('Valid', 0, 0);
        $this->branch('Missing latitude', null, 0);
        $this->branch('Missing longitude', 0, null);
        $this->branch('Latitude too large', 90.000001, 0);
        $this->branch('Latitude too small', -90.000001, 0);
        $this->branch('Longitude too large', 0, 180.000001);
        $this->branch('Longitude too small', 0, -180.000001);
        $origin = new Coordinate(0, 0);
        $all = Branch::query()->withDistance($origin)->orderBy('id')->get();

        self::assertCount(7, $all);
        self::assertSame(0.0, $all[0]->nearby_distance_meters);
        foreach ($all->slice(1) as $branch) {
            self::assertNull($branch->nearby_distance_meters);
            self::assertNull($branch->nearbyDistance());
        }

        self::assertSame([$valid->id], Branch::query()->nearestTo($origin)->get()->modelKeys());
        self::assertSame([$valid->id], Branch::query()->orderByDistance($origin, 'desc')->get()->modelKeys());
        self::assertSame([$valid->id], Branch::query()->withinRadius($origin, Distance::fromKilometers(20000))->get()->modelKeys());
        self::assertSame([$valid->id], Branch::query()->betweenDistances($origin, Distance::fromMeters(0), Distance::fromKilometers(20000))->get()->modelKeys());
    }

    #[DataProvider('zeroRadiusLocations')]
    public function test_zero_radius_includes_equivalent_locations_and_excludes_distinct_points(
        Coordinate $origin,
        float $latitude,
        float $longitude,
        float $otherLatitude,
        float $otherLongitude,
    ): void {
        $first = $this->branch('First', $latitude, $longitude);
        $second = $this->branch('Duplicate', $latitude, $longitude);
        $this->branch('Distinct', $otherLatitude, $otherLongitude);

        self::assertSame(
            [$first->id, $second->id],
            Branch::query()->withinRadius($origin, Distance::fromMeters(0))->orderBy('id')->get()->modelKeys(),
        );
    }

    public static function zeroRadiusLocations(): iterable
    {
        yield 'ordinary duplicate locations' => [new Coordinate(35, 51), 35.0, 51.0, 35.0, 51.000000000001];
        yield 'equivalent antimeridian endpoints' => [new Coordinate(12, 180), 12.0, -180.0, 12.0, 179.999999999999];
        yield 'north pole longitudes' => [new Coordinate(90, -25), 90.0, 150.0, 89.99999999999999, 150.0];
        yield 'south pole longitudes' => [new Coordinate(-90, 60), -90.0, -170.0, -89.99999999999999, -170.0];
    }

    #[DataProvider('geographicReferencePairs')]
    public function test_mysql_distances_match_the_php_calculator(
        Coordinate $origin,
        Coordinate $destination,
        float $absoluteTolerance,
        float $relativeTolerance = 0.0,
    ): void {
        $branch = $this->branch('Reference', $destination->latitude, $destination->longitude);
        $expected = Geo::distance($origin, $destination)->in(Unit::Meter);
        $actual = Branch::query()->whereKey($branch->id)->withDistance($origin)->firstOrFail()->nearby_distance_meters;

        self::assertIsFloat($actual);
        self::assertTrue(is_finite($actual));
        self::assertGreaterThanOrEqual(0.0, $actual);
        self::assertEqualsWithDelta($expected, $actual, max($absoluteTolerance, $expected * $relativeTolerance));
    }

    public static function geographicReferencePairs(): iterable
    {
        yield 'Tehran to Karaj' => [new Coordinate(35.6892, 51.3890), new Coordinate(35.8400, 50.9391), 1.0e-6];
        yield 'one equatorial degree' => [new Coordinate(0, 0), new Coordinate(0, 1), 1.0e-6];
        yield 'ordinary southern coordinates' => [new Coordinate(-33.8688, 151.2093), new Coordinate(-37.8136, 144.9631), 1.0e-6];
        yield 'crossing antimeridian' => [new Coordinate(12, 179.9), new Coordinate(12, -179.9), 1.0e-6];
        yield 'very short antimeridian crossing' => [new Coordinate(0, 179.999999999), new Coordinate(0, -179.999999999), 1.0e-8];
        yield 'very short ordinary distance' => [new Coordinate(0, 0), new Coordinate(0, 1.0e-12), 1.0e-18, 1.0e-12];
        yield 'north pole' => [new Coordinate(90, 0), new Coordinate(90, 180), 0.0];
        yield 'near north pole' => [new Coordinate(89.999999, 0), new Coordinate(89.999999, 180), 1.0e-8];
        yield 'nanometer arc near north pole' => [new Coordinate(89.99999999999999, 0), new Coordinate(89.99999999999999, 180), 1.0e-18, 1.0e-10];
        yield 'antipodes' => [new Coordinate(0, 0), new Coordinate(0, 180), 1.0e-6];
        yield 'oblique antipodes' => [new Coordinate(-41.81, 0), new Coordinate(41.81, 180), 0.5];
        yield 'nearly oblique antipodes' => [new Coordinate(-41.81, 0), new Coordinate(41.81000001, 179.99999999), 0.5];
        yield 'nearly equatorial antipodes' => [new Coordinate(0, 0), new Coordinate(0, 179.9999), 0.001];
    }

    public function test_custom_decimal_columns_table_and_string_primary_key(): void
    {
        CustomBranch::create(['code' => 'z-last', 'name' => 'Tie Z', 'lat' => 0, 'lng' => 1]);
        CustomBranch::create(['code' => 'a-first', 'name' => 'Tie A', 'lat' => 0, 'lng' => -1]);
        CustomBranch::create(['code' => 'nearest', 'name' => 'Nearest', 'lat' => 0, 'lng' => 0.01]);
        $results = CustomBranch::query()->nearestTo(new Coordinate(0, 0), 3)->get();

        self::assertSame(['nearest', 'a-first', 'z-last'], $results->modelKeys());
        self::assertIsFloat($results[0]->nearby_distance_meters);
    }

    public function test_soft_deletes_and_tenant_global_scopes_are_preserved(): void
    {
        $eligible = $this->branch('Eligible', 0, 0.1);
        $this->branch('Other tenant', 0, 0, ['tenant_id' => 2]);
        $this->branch('Deleted', 0, 0, ['deleted_at' => '2026-01-01 00:00:00']);
        $origin = new Coordinate(0, 0);

        self::assertSame([$eligible->id], ScopedBranch::query()->nearestTo($origin)->get()->modelKeys());
        self::assertSame(1, ScopedBranch::query()->withinRadius($origin, Distance::fromKilometers(50))->count());
        self::assertSame(1, ScopedBranch::query()->betweenDistances($origin, Distance::fromMeters(0), Distance::fromKilometers(50))->count());
    }

    public function test_joined_queries_qualify_coordinate_columns_and_select_only_the_model_by_default(): void
    {
        $branch = $this->branch('Joined', 0, 0.01);
        $result = Branch::query()->join('nearby_test_branches as peer', 'peer.id', '=', 'nearby_test_branches.id')
            ->where('peer.name', 'Joined')->withDistance(new Coordinate(0, 0))->firstOrFail();

        self::assertSame($branch->id, $result->id);
        self::assertEqualsWithDelta(1111.950802335329, $result->nearby_distance_meters, 1.0e-7);
    }

    #[DataProvider('saveMethods')]
    public function test_saving_a_hydrated_model_never_persists_the_computed_attribute(string $saveMethod): void
    {
        $branch = $this->branch('Before', 0, 1);
        $selected = Branch::query()->whereKey($branch->id)->withDistance(new Coordinate(0, 0))->firstOrFail();
        $distance = $selected->nearby_distance_meters;
        $selected->name = 'After';

        self::assertTrue($selected->$saveMethod());
        self::assertSame('After', Branch::query()->findOrFail($branch->id)->name);
        self::assertSame($distance, $selected->nearby_distance_meters);
        self::assertFalse(Schema::hasColumn('nearby_test_branches', 'nearby_distance_meters'));
    }

    public static function saveMethods(): iterable
    {
        yield 'normal save' => ['save'];
        yield 'quiet save' => ['saveQuietly'];
    }

    public function test_reserved_alias_in_an_explicit_selection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Branch::query()->selectRaw('id, 7 AS nearby_distance_meters')->withDistance(new Coordinate(0, 0))->get();
    }

    public function test_reserved_real_column_is_rejected_instead_of_overwritten(): void
    {
        Schema::table('nearby_test_branches', static function (Blueprint $table): void {
            $table->double('nearby_distance_meters')->default(7);
        });
        $this->branch('Reserved', 0, 1);

        try {
            Branch::query()->withDistance(new Coordinate(0, 0))->get();
            self::fail('A real reserved distance attribute must not be silently overwritten.');
        } catch (InvalidArgumentException|LogicException $exception) {
            self::assertStringContainsString('nearby_distance_meters', $exception->getMessage());
        }
    }

    public function test_repeated_and_reordered_scopes_keep_independent_bindings_correct(): void
    {
        $west = $this->branch('West', 0, 0);
        $middle = $this->branch('Middle', 0, 1);
        $east = $this->branch('East', 0, 2);
        $westOrigin = new Coordinate(0, 0);
        $eastOrigin = new Coordinate(0, 2);
        $query = Branch::query()->where('is_active', true)->withDistance($westOrigin)
            ->withinRadius($westOrigin, Distance::fromKilometers(120))->withDistance($eastOrigin)
            ->orderByDistance($eastOrigin);
        $results = $query->get();

        self::assertSame([$middle->id, $west->id], $results->modelKeys());
        self::assertEqualsWithDelta(
            Geo::distance($eastOrigin, new Coordinate(0, 1))->in(Unit::Meter),
            $results[0]->nearby_distance_meters,
            1.0e-6,
        );
        self::assertSame([$middle->id], Branch::query()->orderByDistance($eastOrigin)
            ->withinRadius($westOrigin, Distance::fromKilometers(120))
            ->betweenDistances($eastOrigin, Distance::fromMeters(0), Distance::fromKilometers(120))
            ->withDistance($westOrigin)->get()->modelKeys());
        self::assertNotContains($east->id, $results->modelKeys());
    }

    public function test_repeated_nearest_scopes_replace_order_limit_and_selected_origin(): void
    {
        $west = $this->branch('West', 0, 0);
        $east = $this->branch('East', 0, 2);
        $result = Branch::query()->nearestTo(new Coordinate(0, 0), 2)
            ->nearestTo(new Coordinate(0, 2), 1)->get();

        self::assertSame([$east->id], $result->modelKeys());
        self::assertSame(0.0, $result[0]->nearby_distance_meters);
        self::assertNotContains($west->id, $result->modelKeys());
    }

    public function test_nearest_then_radius_then_another_selected_origin_compose_independently(): void
    {
        $nearWest = $this->branch('Near west', 0, 0.01);
        $this->branch('Outside radius', 0, 1);
        $west = new Coordinate(0, 0);
        $east = new Coordinate(0, 2);
        $results = Branch::query()->nearestTo($west, 10)->withinRadius($west, Distance::fromKilometers(5))
            ->withDistance($east)->get();

        self::assertSame([$nearWest->id], $results->modelKeys());
        self::assertEqualsWithDelta(
            Geo::distance($east, new Coordinate(0, 0.01))->in(Unit::Meter),
            $results[0]->nearby_distance_meters,
            1.0e-6,
        );
    }

    public function test_scopes_bind_coordinate_values_and_thresholds_without_executing_queries_during_construction(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $origin = new Coordinate(12.3456789, -123.456789);
        $query = Branch::query()->where('name', 'Bound value')->withDistance($origin)
            ->withinRadius($origin, Distance::fromMeters(9876.54321))->orderByDistance($origin);

        self::assertSame([], DB::getQueryLog());
        $sql = $query->toSql();
        self::assertStringNotContainsString('12.3456789', $sql);
        self::assertStringNotContainsString('-123.456789', $sql);
        self::assertStringNotContainsString('9876.54321', $sql);
        self::assertSame(substr_count($sql, '?'), count($query->getBindings()));
        self::assertCount(0, $query->get());
        DB::disableQueryLog();
    }

    public function test_select_where_and_order_bindings_remain_in_their_own_sql_clauses(): void
    {
        $target = $this->branch('Target', 0, 0.01);
        $this->branch('Excluded', 0, 0);
        $origin = new Coordinate(0, 0);
        $result = Branch::query()->select('id', 'name')->selectRaw('? AS requested_label', ['Selected label'])
            ->where('name', 'Target')->orderByRaw('name = ? DESC', ['Target'])->withDistance($origin)
            ->withinRadius($origin, Distance::fromKilometers(5))->orderByDistance($origin)->firstOrFail();

        self::assertSame($target->id, $result->id);
        self::assertSame('Selected label', $result->requested_label);
        self::assertEqualsWithDelta(1111.950802335329, $result->nearby_distance_meters, 1.0e-7);
    }

    #[DataProvider('unsafeDirections')]
    public function test_unsafe_directions_are_rejected(mixed $direction): void
    {
        $this->expectException(InvalidArgumentException::class);

        Branch::query()->orderByDistance(new Coordinate(0, 0), $direction)->get();
    }

    public static function unsafeDirections(): iterable
    {
        yield 'SQL fragment' => ['asc; DROP TABLE nearby_test_branches'];
        yield 'empty' => [''];
        yield 'other string' => ['sideways'];
        yield 'null' => [null];
        yield 'boolean' => [true];
        yield 'integer' => [1];
        yield 'array' => [[]];
        yield 'object' => [new stdClass()];
    }

    #[DataProvider('invalidLimits')]
    public function test_invalid_nearest_limits_are_rejected_even_from_weak_callers(mixed $limit): void
    {
        $this->expectException(InvalidArgumentException::class);

        WeakLimitCaller::nearest(new Coordinate(0, 0), $limit)->get();
    }

    public static function invalidLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'numeric string' => ['10'];
        yield 'float' => [10.0];
        yield 'fraction' => [1.5];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'null' => [null];
        yield 'array' => [[]];
        yield 'object' => [new stdClass()];
        yield 'NaN' => [NAN];
        yield 'infinity' => [INF];
    }

    public function test_a_reversed_distance_band_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Branch::query()->betweenDistances(new Coordinate(0, 0), Distance::fromKilometers(5), Distance::fromKilometers(1))->get();
    }

    #[DataProvider('unsafeColumns')]
    public function test_unsafe_model_column_identifiers_are_rejected(string $column, bool $latitude): void
    {
        if ($latitude) {
            UnsafeBranch::$latitudeColumn = $column;
        } else {
            UnsafeBranch::$longitudeColumn = $column;
        }

        $this->expectException(InvalidArgumentException::class);

        UnsafeBranch::query()->withDistance(new Coordinate(0, 0))->get();
    }

    public static function unsafeColumns(): iterable
    {
        foreach (['latitude); DROP TABLE nearby_test_branches; --', 'latitude + 1', 'latitude`', 'other.latitude', '', '*'] as $index => $column) {
            yield 'latitude '.$index => [$column, true];
            yield 'longitude '.$index => [$column, false];
        }
    }

    public function test_unsupported_drivers_fail_clearly_before_mysql_sql_is_executed(): void
    {
        config()->set('database.connections.nearby_unsupported', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        try {
            Branch::on('nearby_unsupported')->withinRadius(new Coordinate(0, 0), Distance::fromMeters(1))->get();
            self::fail('Unsupported drivers must fail clearly.');
        } catch (InvalidArgumentException|RuntimeException|LogicException $exception) {
            self::assertMatchesRegularExpression('/MySQL|unsupported|driver/i', $exception->getMessage());
        }
    }

    public function test_an_aliased_model_from_source_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FROM');

        Branch::query()->from('nearby_test_branches as branches')->withDistance(new Coordinate(0, 0))->get();
    }

    public function test_changing_the_from_source_after_a_scope_is_also_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FROM');

        Branch::query()->withDistance(new Coordinate(0, 0))->from('nearby_test_branches as branches')->get();
    }
}
