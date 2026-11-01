<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Tests\Integration\Fixtures\AppendedBranch;
use Zarbinco\Nearby\Tests\Integration\Fixtures\Branch;

final class DistanceSerializationTest extends MySqlTestCase
{
    public function test_plain_models_keep_their_original_serialized_fields(): void
    {
        $this->branch('Plain', 0, 1);
        $model = Branch::query()->firstOrFail();

        self::assertArrayNotHasKey('nearby_distance_meters', $model->toArray());
        self::assertArrayNotHasKey('nearby_distance_meters', json_decode($model->toJson(), true, flags: JSON_THROW_ON_ERROR));
        self::assertNull($model->nearby_distance_meters);
        self::assertNull($model->nearbyDistance());
    }

    #[DataProvider('distanceLoaders')]
    public function test_distance_loaded_models_include_a_numeric_field_in_arrays_and_json(string $scope): void
    {
        $this->branch('Calculated', 0, 1);
        $model = Branch::query()->$scope(new Coordinate(0, 0))->firstOrFail();

        self::assertIsFloat($model->toArray()['nearby_distance_meters']);
        self::assertIsFloat(json_decode($model->toJson(), true, flags: JSON_THROW_ON_ERROR)['nearby_distance_meters']);
        self::assertArrayNotHasKey('nearby_distance_meters', $model->getAttributes());
        self::assertSame([], $model->getDirty());
    }

    public static function distanceLoaders(): iterable
    {
        yield 'with distance' => ['withDistance'];
        yield 'nearest' => ['nearestTo'];
    }

    public function test_selected_null_distance_is_serialized_while_plain_null_is_not(): void
    {
        $this->branch('Missing', null, 0);
        $selected = Branch::query()->withDistance(new Coordinate(0, 0))->firstOrFail();

        self::assertArrayHasKey('nearby_distance_meters', $selected->toArray());
        self::assertNull($selected->toArray()['nearby_distance_meters']);
        self::assertNull($selected->nearbyDistance());
        self::assertArrayNotHasKey('nearby_distance_meters', Branch::query()->firstOrFail()->toArray());
    }

    public function test_explicit_appends_remain_available_for_plain_models(): void
    {
        $this->branch('Explicit', 0, 1);
        $model = Branch::query()->firstOrFail()->append('nearby_distance_meters');

        self::assertArrayHasKey('nearby_distance_meters', $model->toArray());
        self::assertNull($model->toArray()['nearby_distance_meters']);
        self::assertContains('nearby_distance_meters', $model->getAppends());
    }

    public function test_model_configured_appends_are_preserved_with_and_without_a_calculation(): void
    {
        $this->branch('Configured', 0, 1);
        $plain = AppendedBranch::query()->firstOrFail()->toArray();
        $calculated = AppendedBranch::query()->withDistance(new Coordinate(0, 0))->firstOrFail()->toArray();

        self::assertSame('CONFIGURED', $plain['display_name']);
        self::assertNull($plain['nearby_distance_meters']);
        self::assertSame('CONFIGURED', $calculated['display_name']);
        self::assertIsFloat($calculated['nearby_distance_meters']);
    }

    public function test_hidden_visible_and_explicit_append_controls_are_respected(): void
    {
        $this->branch('Visibility', 0, 1);
        $model = Branch::query()->withDistance(new Coordinate(0, 0))->firstOrFail();
        $model->setHidden(['nearby_distance_meters']);
        self::assertArrayNotHasKey('nearby_distance_meters', $model->toArray());

        $model->setHidden([])->setVisible(['id', 'name']);
        self::assertSame(['id', 'name'], array_keys($model->toArray()));

        $model->setVisible(['id', 'nearby_distance_meters']);
        self::assertIsFloat($model->toArray()['nearby_distance_meters']);

        $model->setAppends([]);
        self::assertArrayNotHasKey('nearby_distance_meters', $model->toArray());
        self::assertIsFloat($model->nearby_distance_meters);
    }

    public function test_paginated_models_serialize_distance_only_when_it_was_requested(): void
    {
        $this->branch('First', 0, 0.1);
        $this->branch('Second', 0, 1);
        $plain = Branch::query()->orderBy('id')->paginate(1)->toArray();
        $calculated = Branch::query()->withDistance(new Coordinate(0, 0))->orderByDistance(new Coordinate(0, 0))
            ->paginate(1)->toArray();

        self::assertArrayNotHasKey('nearby_distance_meters', $plain['data'][0]);
        self::assertIsFloat($calculated['data'][0]['nearby_distance_meters']);
        self::assertSame(2, $plain['total']);
        self::assertSame(2, $calculated['total']);
    }

    #[DataProvider('saveMethods')]
    public function test_saving_preserves_serialized_calculations_without_persisting_them(string $method): void
    {
        $branch = $this->branch('Before', 0, 1);
        $selected = Branch::query()->withDistance(new Coordinate(0, 0))->firstOrFail();
        $before = $selected->toArray()['nearby_distance_meters'];
        $selected->name = 'After';

        self::assertTrue($selected->$method());
        self::assertSame($before, $selected->toArray()['nearby_distance_meters']);
        self::assertArrayNotHasKey('nearby_distance_meters', $selected->getAttributes());
        self::assertArrayNotHasKey('nearby_distance_meters', Branch::query()->findOrFail($branch->id)->toArray());
    }

    public static function saveMethods(): iterable
    {
        yield 'save' => ['save'];
        yield 'save quietly' => ['saveQuietly'];
    }
}
