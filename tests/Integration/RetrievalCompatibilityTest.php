<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Geo;
use Zarbinco\Nearby\Tests\Integration\Fixtures\Branch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\Region;
use Zarbinco\Nearby\Tests\Integration\Fixtures\RelatedBranch;
use Zarbinco\Nearby\Unit;

final class RetrievalCompatibilityTest extends MySqlTestCase
{
    public function test_cursor_returns_normal_models_with_filters_ordering_and_computed_distance(): void
    {
        $expected = $this->seedRetrievalBranches();
        $cursor = $this->boundedQuery()->cursor();

        self::assertInstanceOf(LazyCollection::class, $cursor);
        $models = $cursor->all();
        self::assertSame($expected, array_map(static fn (Branch $branch): int => $branch->id, $models));
        foreach ($models as $model) {
            $this->assertHydratedDistance($model);
        }
    }

    public function test_chunk_preserves_the_nearest_limit_and_hydrates_each_batch(): void
    {
        $expected = array_slice($this->seedRetrievalBranches(), 0, 2);
        $actual = [];
        $batchSizes = [];
        $completed = $this->boundedQuery()->nearestTo(new Coordinate(0, 0), 2)->chunk(1,
            function (EloquentCollection $models) use (&$actual, &$batchSizes): void {
                $batchSizes[] = $models->count();
                foreach ($models as $model) {
                    $actual[] = $model->id;
                    $this->assertHydratedDistance($model);
                }
            },
        );

        self::assertTrue($completed);
        self::assertSame($expected, $actual);
        self::assertSame([1, 1], $batchSizes);
    }

    public function test_lazy_preserves_the_nearest_limit_and_computed_distance_across_batches(): void
    {
        $expected = array_slice($this->seedRetrievalBranches(), 0, 2);
        $lazy = $this->boundedQuery()->nearestTo(new Coordinate(0, 0), 2)->lazy(1);

        self::assertInstanceOf(LazyCollection::class, $lazy);
        $models = $lazy->all();
        self::assertSame($expected, array_map(static fn (Branch $model): int => $model->id, $models));
        foreach ($models as $model) {
            $this->assertHydratedDistance($model);
        }
    }

    public function test_real_column_plucks_remain_normal_scalar_collections_with_distance_ordering(): void
    {
        $expected = $this->seedRetrievalBranches();
        $query = $this->boundedQuery();
        $ids = (clone $query)->pluck('id');
        $namesById = (clone $query)->pluck('name', 'id');

        self::assertInstanceOf(Collection::class, $ids);
        self::assertNotInstanceOf(EloquentCollection::class, $ids);
        self::assertSame($expected, $ids->all());
        self::assertSame(['Closest', 'Equal first', 'Equal second'], array_values($namesById->all()));
        self::assertSame($expected, array_keys($namesById->all()));
    }

    public function test_value_reads_real_and_virtual_attributes_from_the_first_distance_ordered_model(): void
    {
        $this->seedRetrievalBranches();
        $query = $this->boundedQuery();

        self::assertSame('Closest', (clone $query)->value('name'));
        self::assertEqualsWithDelta(
            Geo::distance(new Coordinate(0, 0), new Coordinate(0, 0.001))->in(Unit::Meter),
            (clone $query)->value('nearby_distance_meters'),
            1.0e-6,
        );
        self::assertNull((clone $query)->where('name', 'Absent')->value('name'));
        self::assertNull((clone $query)->where('name', 'Absent')->value('nearby_distance_meters'));
    }

    public function test_first_or_fail_returns_a_hydrated_distance_model(): void
    {
        $expected = $this->seedRetrievalBranches();
        $model = $this->boundedQuery()->firstOrFail();

        self::assertSame($expected[0], $model->id);
        $this->assertHydratedDistance($model);
    }

    public function test_first_or_fail_keeps_laravels_missing_model_exception(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->boundedQuery()->firstOrFail();
    }

    public function test_simple_pagination_preserves_distance_order_and_serialized_values(): void
    {
        $expected = $this->seedRetrievalBranches();
        $query = $this->boundedQuery();
        $first = (clone $query)->simplePaginate(2, ['*'], 'page', 1);
        $second = (clone $query)->simplePaginate(2, ['*'], 'page', 2);

        self::assertInstanceOf(Paginator::class, $first);
        self::assertSame(array_slice($expected, 0, 2), $first->getCollection()->modelKeys());
        self::assertSame(array_slice($expected, 2), $second->getCollection()->modelKeys());
        self::assertTrue($first->hasMorePages());
        self::assertFalse($second->hasMorePages());
        self::assertIsFloat($first->toArray()['data'][0]['nearby_distance_meters']);
        self::assertIsFloat($second->toArray()['data'][0]['nearby_distance_meters']);
    }

    public function test_reused_and_cloned_builders_hydrate_independent_models_without_changing_the_query(): void
    {
        $expected = $this->seedRetrievalBranches();
        $query = $this->boundedQuery();
        $bindings = $query->getBindings();
        $first = $query->get();
        $second = $query->get();
        $clone = clone $query;
        $third = $clone->where('name', 'Equal second')->get();

        self::assertSame($expected, $first->modelKeys());
        self::assertSame($expected, $second->modelKeys());
        self::assertSame([$expected[2]], $third->modelKeys());
        self::assertNotSame($first[0], $second[0]);
        self::assertSame($first[0]->nearby_distance_meters, $second[0]->nearby_distance_meters);
        self::assertSame($bindings, $query->getBindings());
        self::assertSame($expected, $query->get()->modelKeys());
    }

    public function test_eager_loading_and_relationship_filters_preserve_selected_attributes_and_tenant_isolation(): void
    {
        [$allowedRegion, $eligible] = $this->seedRelatedBranches();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $results = RelatedBranch::query()->select('nearby_test_branches.id', 'name', 'region_id')
            ->with('region:id,name')->whereHas('region', static fn (Builder $query) => $query->where('is_enabled', true))
            ->withinRadius(new Coordinate(0, 0), Distance::fromKilometers(5))
            ->nearestTo(new Coordinate(0, 0))->get();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertSame([$eligible->id], $results->modelKeys());
        self::assertCount(2, $queries);
        self::assertTrue($results[0]->relationLoaded('region'));
        self::assertSame($allowedRegion->id, $results[0]->region->id);
        self::assertSame('Allowed', $results[0]->region->name);
        self::assertArrayNotHasKey('latitude', $results[0]->getAttributes());
        self::assertIsFloat($results[0]->nearby_distance_meters);
        self::assertSame('Allowed', $results[0]->toArray()['region']['name']);
    }

    public function test_a_belongs_to_parent_can_eager_load_nearby_children_without_losing_relationship_constraints(): void
    {
        [$region, $eligible] = $this->seedRelatedBranches();
        $result = Region::query()->whereKey($region->id)->with([
            'branches' => static fn ($query) => $query->withinRadius(new Coordinate(0, 0), Distance::fromKilometers(5))
                ->withDistance(new Coordinate(0, 0))->orderByDistance(new Coordinate(0, 0)),
        ])->firstOrFail();

        self::assertSame([$eligible->id], $result->branches->modelKeys());
        $this->assertHydratedDistance($result->branches[0]);
        self::assertArrayNotHasKey('nearby_distance_meters', $result->toArray());
    }

    #[DataProvider('streamingMethods')]
    public function test_streaming_methods_keep_empty_results_empty(string $method): void
    {
        $results = $this->boundedQuery()->$method();

        self::assertInstanceOf(LazyCollection::class, $results);
        self::assertSame([], $results->all());
    }

    public static function streamingMethods(): iterable
    {
        yield 'cursor' => ['cursor'];
        yield 'lazy' => ['lazy'];
    }

    /** @return list<int> */
    private function seedRetrievalBranches(): array
    {
        $this->branch('Far', 0, 0.2);
        $equalFirst = $this->branch('Equal first', 0, 0.02);
        $equalSecond = $this->branch('Equal second', 0, -0.02);
        $closest = $this->branch('Closest', 0, 0.001);
        $this->branch('Inactive', 0, 0, ['is_active' => false]);
        $this->branch('Missing', null, null);

        return [$closest->id, $equalFirst->id, $equalSecond->id];
    }

    private function boundedQuery(): Builder
    {
        return Branch::query()->where('is_active', true)
            ->withinRadius(new Coordinate(0, 0), Distance::fromKilometers(5))
            ->withDistance(new Coordinate(0, 0))->orderByDistance(new Coordinate(0, 0));
    }

    private function assertHydratedDistance(Branch $model): void
    {
        self::assertIsFloat($model->nearby_distance_meters);
        self::assertInstanceOf(Distance::class, $model->nearbyDistance());
        self::assertSame($model->nearby_distance_meters, $model->nearbyDistance()->in(Unit::Meter));
        self::assertIsFloat($model->toArray()['nearby_distance_meters']);
        self::assertArrayNotHasKey('nearby_distance_meters', $model->getAttributes());
        foreach (array_keys($model->toArray()) as $attribute) {
            self::assertStringStartsNotWith('__nearby_', $attribute);
        }
    }

    /** @return array{Region, Branch} */
    private function seedRelatedBranches(): array
    {
        Schema::dropIfExists('nearby_test_regions');
        Schema::create('nearby_test_regions', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_enabled')->default(true);
        });
        Schema::table('nearby_test_branches', static fn (Blueprint $table) => $table->unsignedBigInteger('region_id')->nullable());
        $allowed = Region::create(['name' => 'Allowed', 'is_enabled' => true]);
        $disabled = Region::create(['name' => 'Disabled', 'is_enabled' => false]);
        $eligible = $this->branch('Eligible', 0, 0.001, ['region_id' => $allowed->id]);
        $this->branch('Disabled region', 0, 0.002, ['region_id' => $disabled->id]);
        $this->branch('Other tenant', 0, 0, ['region_id' => $allowed->id, 'tenant_id' => 2]);
        $this->branch('Deleted', 0, 0, ['region_id' => $allowed->id, 'deleted_at' => '2026-01-01 00:00:00']);
        $this->branch('Far from region', 0, 1, ['region_id' => $allowed->id]);

        return [$allowed, $eligible];
    }
}
