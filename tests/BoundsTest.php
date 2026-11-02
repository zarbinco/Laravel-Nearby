<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests;

use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Zarbinco\Nearby\Bounds;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Exceptions\InvalidCoordinateException;
use Zarbinco\Nearby\Tests\Fixtures\WeakBoundsCaller;

final class BoundsTest extends TestCase
{
    public function test_integer_and_float_boundaries_are_immutable_public_floats(): void
    {
        $bounds = new Bounds(south: -20, west: 175.5, north: 20, east: -175.25);

        self::assertSame([-20.0, 175.5, 20.0, -175.25], [$bounds->south, $bounds->west, $bounds->north, $bounds->east]);
        self::assertTrue($bounds->crossesAntimeridian());
    }

    #[DataProvider('boundaryProperties')]
    public function test_boundaries_cannot_be_modified_or_unset(string $property): void
    {
        $bounds = new Bounds(-20, -30, 20, 30);

        try {
            $bounds->$property = 0.0;
            self::fail('A boundary must be immutable.');
        } catch (Error) {
            self::assertSame([-20.0, -30.0, 20.0, 30.0], [$bounds->south, $bounds->west, $bounds->north, $bounds->east]);
        }

        $this->expectException(Error::class);
        unset($bounds->$property);
    }

    public static function boundaryProperties(): iterable
    {
        foreach (['south', 'west', 'north', 'east'] as $property) {
            yield $property => [$property];
        }
    }

    #[DataProvider('invalidInputs')]
    public function test_constructor_rejects_invalid_types_and_nonfinite_values(string $argument, mixed $value): void
    {
        $arguments = ['south' => -20, 'west' => -30, 'north' => 20, 'east' => 30];
        $arguments[$argument] = $value;
        $this->expectException(InvalidCoordinateException::class);
        $this->expectExceptionMessageMatches('/'.$argument.'/i');

        new Bounds(...$arguments);
    }

    #[DataProvider('invalidInputs')]
    public function test_viewport_rejects_invalid_types_and_nonfinite_values(string $argument, mixed $value): void
    {
        $arguments = ['south' => -20, 'west' => -30, 'north' => 20, 'east' => 30];
        $arguments[$argument] = $value;
        $this->expectException(InvalidCoordinateException::class);
        $this->expectExceptionMessageMatches('/'.$argument.'/i');

        Bounds::fromViewport(...$arguments);
    }

    public static function invalidInputs(): iterable
    {
        foreach (['south', 'west', 'north', 'east'] as $argument) {
            foreach (['numeric string' => '1', 'nonnumeric string' => 'east', 'true' => true, 'false' => false,
                'null' => null, 'array' => [], 'object' => new stdClass(), 'NaN' => NAN,
                'positive infinity' => INF, 'negative infinity' => -INF] as $name => $value) {
                yield $argument.' '.$name => [$argument, $value];
            }
        }
    }

    #[DataProvider('invalidRanges')]
    public function test_constructor_rejects_invalid_ranges(array $arguments, string $message): void
    {
        $this->expectException(InvalidCoordinateException::class);
        $this->expectExceptionMessageMatches('/'.$message.'/i');

        new Bounds(...$arguments);
    }

    public static function invalidRanges(): iterable
    {
        yield 'south too low' => [[-90.000001, 0, 0, 1], 'south'];
        yield 'south too high' => [[90.000001, 0, 90, 1], 'south'];
        yield 'north too low' => [[-90, 0, -90.000001, 1], 'north'];
        yield 'north too high' => [[0, 0, 90.000001, 1], 'north'];
        yield 'west too low' => [[0, -180.000001, 1, 0], 'west'];
        yield 'west too high' => [[0, 180.000001, 1, 0], 'west'];
        yield 'east too low' => [[0, 0, 1, -180.000001], 'east'];
        yield 'east too high' => [[0, 0, 1, 180.000001], 'east'];
        yield 'inverted latitude' => [[1, 0, -1, 1], 'south'];
    }

    #[DataProvider('weakInvalidScalars')]
    public function test_weak_callers_cannot_coerce_boundary_values(string $method, string $argument, mixed $value): void
    {
        $arguments = ['south' => -20, 'west' => -30, 'north' => 20, 'east' => 30];
        $arguments[$argument] = $value;
        $this->expectException(InvalidCoordinateException::class);

        WeakBoundsCaller::$method(...$arguments);
    }

    public static function weakInvalidScalars(): iterable
    {
        foreach (['construct', 'viewport'] as $method) {
            foreach (['south', 'west', 'north', 'east'] as $argument) {
                foreach (['0', true, false] as $index => $value) {
                    yield $method.' '.$argument.' '.$index => [$method, $argument, $value];
                }
            }
        }
    }

    #[DataProvider('ordinaryPoints')]
    public function test_contains_uses_exact_inclusive_boundaries(float $latitude, float $longitude, bool $expected): void
    {
        $bounds = new Bounds(35.6, 51.2, 35.8, 51.5);

        self::assertSame($expected, $bounds->contains(new Coordinate($latitude, $longitude)));
        self::assertFalse($bounds->crossesAntimeridian());
    }

    public static function ordinaryPoints(): iterable
    {
        yield 'inside' => [35.7, 51.3, true];
        yield 'southwest corner' => [35.6, 51.2, true];
        yield 'southeast corner' => [35.6, 51.5, true];
        yield 'northwest corner' => [35.8, 51.2, true];
        yield 'northeast corner' => [35.8, 51.5, true];
        yield 'south edge' => [35.6, 51.3, true];
        yield 'north edge' => [35.8, 51.3, true];
        yield 'west edge' => [35.7, 51.2, true];
        yield 'east edge' => [35.7, 51.5, true];
        yield 'just south' => [35.599999999, 51.3, false];
        yield 'just north' => [35.800000001, 51.3, false];
        yield 'just west' => [35.7, 51.199999999, false];
        yield 'just east' => [35.7, 51.500000001, false];
    }

    #[DataProvider('longitudeIntervals')]
    public function test_signed_antimeridian_endpoints_and_crossing_intervals(
        float $west,
        float $east,
        bool $crosses,
        array $included,
        array $excluded,
    ): void {
        $bounds = new Bounds(0, $west, 10, $east);

        self::assertSame($west, $bounds->west);
        self::assertSame($east, $bounds->east);
        self::assertSame($crosses, $bounds->crossesAntimeridian());

        foreach ($included as $longitude) {
            self::assertTrue($bounds->contains(new Coordinate(5, $longitude)), 'Expected longitude '.$longitude.' inside.');
        }

        foreach ($excluded as $longitude) {
            self::assertFalse($bounds->contains(new Coordinate(5, $longitude)), 'Expected longitude '.$longitude.' outside.');
        }

        self::assertFalse($bounds->contains(new Coordinate(-1, 180)));
        self::assertFalse($bounds->contains(new Coordinate(11, -180)));
    }

    public static function longitudeIntervals(): iterable
    {
        yield 'full world' => [-180.0, 180.0, false, [-180, -170, 0, 170, 180], []];
        yield 'reversed dateline only' => [180.0, -180.0, true, [-180, 180], [-179, 0, 179]];
        yield 'west touching dateline' => [-180.0, -170.0, false, [-180, 180, -175, -170], [-169, 0, 179]];
        yield 'east touching dateline' => [170.0, 180.0, false, [170, 175, 180, -180], [169, 0, -179]];
        yield 'wrapped' => [170.0, -170.0, true, [170, 175, -175, -170, 180, -180], [169, 0, -169]];
        yield 'positive dateline zero width' => [180.0, 180.0, false, [-180, 180], [-179, 0, 179]];
        yield 'negative dateline zero width' => [-180.0, -180.0, false, [-180, 180], [-179, 0, 179]];
        yield 'ordinary zero width' => [30.0, 30.0, false, [30], [29.999999999, 30.000000001, 0]];
    }

    public function test_zero_height_and_both_polar_latitudes_keep_longitude_constraints(): void
    {
        foreach ([-90, 0, 90] as $latitude) {
            $bounds = new Bounds($latitude, -10, $latitude, 10);

            self::assertTrue($bounds->contains(new Coordinate($latitude, 0)));
            self::assertTrue($bounds->contains(new Coordinate($latitude, -10)));
            self::assertTrue($bounds->contains(new Coordinate($latitude, 10)));
            self::assertFalse($bounds->contains(new Coordinate($latitude, 90)));
        }

        self::assertFalse((new Bounds(0, -180, 0, 180))->contains(new Coordinate(1.0e-20, 0)));
    }

    #[DataProvider('normalizedViewports')]
    public function test_viewport_preserves_extent_before_normalizing_longitudes(
        int|float $west,
        int|float $east,
        float $expectedWest,
        float $expectedEast,
    ): void {
        $bounds = Bounds::fromViewport(south: -20, west: $west, north: 20, east: $east);

        self::assertSame([-20.0, $expectedWest, 20.0, $expectedEast], [$bounds->south, $bounds->west, $bounds->north, $bounds->east]);
        self::assertSame($expectedWest > $expectedEast, $bounds->crossesAntimeridian());
    }

    public static function normalizedViewports(): iterable
    {
        yield 'crossing eastward' => [175, 185, 175.0, -175.0];
        yield 'negative world offsets' => [-540, -530, -180.0, -170.0];
        yield 'further negative offsets' => [-900, -890, -180.0, -170.0];
        yield 'positive world offsets' => [540, 550, 180.0, -170.0];
        yield 'many world offsets' => [360_000_175, 360_000_185, 175.0, -175.0];
        yield 'exact full world' => [10, 370, -180.0, 180.0];
        yield 'signed full world' => [-180, 180, -180.0, 180.0];
        yield 'more than one world' => [10, 730, -180.0, 180.0];
        yield 'opposite world offsets' => [-1000, 1000, -180.0, 180.0];
        yield 'ordinary zero width' => [30, 30, 30.0, 30.0];
        yield 'negative dateline zero width' => [-540, -540, -180.0, -180.0];
        yield 'positive dateline zero width' => [540, 540, 180.0, 180.0];
        yield 'offset zero meridian' => [360, 360, 0.0, 0.0];
        yield 'upper safe integer magnitude' => [9_007_199_254_740_981, 9_007_199_254_740_991, 21.0, 31.0];
        yield 'lower safe integer magnitude' => [-9_007_199_254_740_991, -9_007_199_254_740_981, -31.0, -21.0];
    }

    public function test_viewport_keeps_tiny_intervals_and_gaps_without_rounding_or_tolerance(): void
    {
        $tiny = Bounds::fromViewport(-1, -1.0e-20, 1, 1.0e-20);
        self::assertSame(-1.0e-20, $tiny->west);
        self::assertSame(1.0e-20, $tiny->east);
        self::assertTrue($tiny->contains(new Coordinate(0, 0)));
        self::assertFalse($tiny->contains(new Coordinate(0, 1.0e-19)));

        $almostWorld = Bounds::fromViewport(-1, 1.0e-20, 1, 360);
        self::assertSame(1.0e-20, $almostWorld->west);
        self::assertSame(0.0, $almostWorld->east);
        self::assertTrue($almostWorld->crossesAntimeridian());
        self::assertTrue($almostWorld->contains(new Coordinate(0, 0)));
        self::assertTrue($almostWorld->contains(new Coordinate(0, 1.0e-20)));
        self::assertFalse($almostWorld->contains(new Coordinate(0, 1.0e-21)));

        $aboveWorld = Bounds::fromViewport(-1, -1.0e-20, 1, 360);
        self::assertSame([-180.0, 180.0], [$aboveWorld->west, $aboveWorld->east]);
        self::assertTrue($aboveWorld->contains(new Coordinate(0, 1.0e-21)));
    }

    public function test_viewport_does_not_turn_a_nearly_full_interval_into_full_world(): void
    {
        $east = 179.99999999999997;
        $bounds = Bounds::fromViewport(-1, -180, 1, $east);

        self::assertSame(-180.0, $bounds->west);
        self::assertSame($east, $bounds->east);
        self::assertFalse($bounds->crossesAntimeridian());
        self::assertTrue($bounds->contains(new Coordinate(0, -180)));
    }

    public function test_viewport_retains_very_short_intervals_on_both_sides_of_the_dateline(): void
    {
        $bounds = Bounds::fromViewport(-1, 179.99999999999997, 1, 180.00000000000003);

        self::assertSame(179.99999999999997, $bounds->west);
        self::assertSame(-179.99999999999997, $bounds->east);
        self::assertTrue($bounds->contains(new Coordinate(0, 180)));
        self::assertTrue($bounds->contains(new Coordinate(0, $bounds->west)));
        self::assertTrue($bounds->contains(new Coordinate(0, $bounds->east)));
        self::assertFalse($bounds->contains(new Coordinate(0, 179.99999999999994)));
        self::assertFalse($bounds->contains(new Coordinate(0, -179.99999999999994)));
    }

    public function test_viewport_accepts_decimal_arcs_whose_equivalent_span_sums_round_differently(): void
    {
        $bounds = Bounds::fromViewport(-1, -170.1, 1, 185.2);

        self::assertSame(-170.1, $bounds->west);
        self::assertSame(-174.8, $bounds->east);
        self::assertTrue($bounds->contains(new Coordinate(0, 180)));
        self::assertFalse($bounds->contains(new Coordinate(0, -172)));
    }

    #[DataProvider('invalidViewports')]
    public function test_viewport_rejects_inverted_or_numerically_unsafe_inputs(array $arguments): void
    {
        $this->expectException(InvalidCoordinateException::class);

        Bounds::fromViewport(...$arguments);
    }

    public static function invalidViewports(): iterable
    {
        yield 'inverted longitude' => [[-20, 170, 20, -170]];
        yield 'inverted latitude' => [[20, 0, -20, 10]];
        yield 'invalid south' => [[-90.000001, 0, 20, 10]];
        yield 'invalid north' => [[-20, 0, 90.000001, 10]];
        yield 'first unsafe positive integer' => [[-20, 0, 20, 9_007_199_254_740_992]];
        yield 'first unsafe negative integer' => [[-20, -9_007_199_254_740_992, 20, 0]];
        yield 'PHP maximum integer' => [[-20, 0, 20, PHP_INT_MAX]];
        yield 'huge finite positive float' => [[-20, 0, 20, PHP_FLOAT_MAX]];
        yield 'huge finite negative float' => [[-20, -PHP_FLOAT_MAX, 20, 0]];
    }
}
