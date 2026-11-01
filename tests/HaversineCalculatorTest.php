<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\Nearby\Calculators\HaversineCalculator;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Geo;
use Zarbinco\Nearby\Unit;

final class HaversineCalculatorTest extends TestCase
{
    #[DataProvider('identicalLocations')]
    public function test_identical_locations_have_exactly_zero_distance(Coordinate $origin, Coordinate $destination): void
    {
        self::assertSame(0.0, Geo::distance($origin, $destination)->in(Unit::Meter));
    }

    public static function identicalLocations(): iterable
    {
        yield 'same point' => [new Coordinate(35.6892, 51.389), new Coordinate(35.6892, 51.389)];
        yield 'zero' => [new Coordinate(0, 0), new Coordinate(0, 0)];
        yield 'canonical antimeridian' => [new Coordinate(15, -180), new Coordinate(15, 180)];
        yield 'north pole' => [new Coordinate(90, -70), new Coordinate(90, 120)];
        yield 'south pole' => [new Coordinate(-90, -180), new Coordinate(-90, 0)];
    }

    #[DataProvider('analyticArcs')]
    public function test_analytic_great_circle_arcs(
        Coordinate $origin,
        Coordinate $destination,
        float $angleInDegrees,
        float $tolerance,
    ): void {
        // A great-circle arc has length radius times its angle in radians.
        $expected = HaversineCalculator::MEAN_EARTH_RADIUS_METERS * M_PI * $angleInDegrees / 180.0;

        self::assertEqualsWithDelta($expected, Geo::distance($origin, $destination)->in(Unit::Meter), $tolerance);
    }

    public static function analyticArcs(): iterable
    {
        yield 'one equatorial degree' => [new Coordinate(0, 0), new Coordinate(0, 1), 1.0, 1.0e-8];
        yield 'quarter circumference along equator' => [new Coordinate(0, 0), new Coordinate(0, 90), 90.0, 1.0e-8];
        yield 'quarter circumference along meridian' => [new Coordinate(0, 40), new Coordinate(90, 40), 90.0, 1.0e-8];
        yield 'quarter circumference with orthogonal radius vectors' => [new Coordinate(45, 0), new Coordinate(0, 90), 90.0, 1.0e-8];
        yield 'equatorial antipodes' => [new Coordinate(0, 0), new Coordinate(0, 180), 180.0, 1.0e-8];
        yield 'oblique antipodes' => [new Coordinate(-41.81, 0), new Coordinate(41.81, 180), 180.0, 0.5];
        yield 'polar antipodes' => [new Coordinate(-90, 30), new Coordinate(90, -150), 180.0, 1.0e-8];
        yield 'nearly antipodal' => [new Coordinate(0, 0), new Coordinate(0, 179.9999), 179.9999, 0.001];
        yield 'across antimeridian' => [new Coordinate(0, 179), new Coordinate(0, -179), 2.0, 1.0e-8];
        yield 'near north pole along meridian' => [new Coordinate(89, 25), new Coordinate(89.5, 25), 0.5, 1.0e-8];
        yield 'near south pole along meridian' => [new Coordinate(-89, -25), new Coordinate(-89.5, -25), 0.5, 1.0e-8];
        yield 'across north pole' => [new Coordinate(89.9, 0), new Coordinate(89.9, 180), 0.2, 1.0e-8];
    }

    public function test_very_short_distances_are_not_rounded_to_zero(): void
    {
        $distance = Geo::distance(new Coordinate(0, 0), new Coordinate(0, 1.0e-12))->in(Unit::Meter);
        $expected = HaversineCalculator::MEAN_EARTH_RADIUS_METERS * M_PI * 1.0e-12 / 180.0;

        self::assertGreaterThan(0.0, $distance);
        self::assertEqualsWithDelta($expected, $distance, $expected * 1.0e-12);
    }

    public function test_one_equatorial_degree_matches_the_specified_earth_model(): void
    {
        $actual = Geo::distance(new Coordinate(0, 0), new Coordinate(0, 1))->in(Unit::Meter);

        self::assertEqualsWithDelta(111195.0802335329, $actual, 1.0e-8);
    }

    public function test_very_short_antimeridian_crossings_retain_precision(): void
    {
        $origin = new Coordinate(0, 179.999999999);
        $destination = new Coordinate(0, -179.999999999);
        $angle = (180.0 - $origin->longitude) + ($destination->longitude + 180.0);
        $expected = HaversineCalculator::MEAN_EARTH_RADIUS_METERS * M_PI * $angle / 180.0;
        $actual = Geo::distance($origin, $destination)->in(Unit::Meter);

        self::assertGreaterThan(0.0, $actual);
        self::assertEqualsWithDelta($expected, $actual, 1.0e-8);
    }

    public function test_near_pole_longitude_changes_are_not_treated_as_the_same_pole(): void
    {
        $actual = Geo::distance(new Coordinate(89.999999, 0), new Coordinate(89.999999, 180))->in(Unit::Meter);
        $angle = 2.0 * (90.0 - 89.999999);
        $expected = HaversineCalculator::MEAN_EARTH_RADIUS_METERS * M_PI * $angle / 180.0;

        self::assertGreaterThan(0.0, $actual);
        self::assertEqualsWithDelta($expected, $actual, 1.0e-8);
    }

    #[DataProvider('extremelyNearPoleLatitudes')]
    public function test_nanometer_arcs_next_to_the_poles_retain_relative_precision(float $latitude): void
    {
        $actual = Geo::distance(new Coordinate($latitude, 0), new Coordinate($latitude, 180))->in(Unit::Meter);
        $angle = 2.0 * (90.0 - abs($latitude));
        $expected = HaversineCalculator::MEAN_EARTH_RADIUS_METERS * M_PI * $angle / 180.0;

        self::assertGreaterThan(0.0, $actual);
        self::assertEqualsWithDelta($expected, $actual, $expected * 1.0e-12);
    }

    public static function extremelyNearPoleLatitudes(): iterable
    {
        yield 'north pole' => [89.99999999999999];
        yield 'south pole' => [-89.99999999999999];
    }

    #[DataProvider('representativePairs')]
    public function test_distances_are_symmetric_finite_and_bounded(Coordinate $origin, Coordinate $destination): void
    {
        $forward = Geo::distance($origin, $destination)->in(Unit::Meter);
        $reverse = Geo::distance($destination, $origin)->in(Unit::Meter);

        self::assertTrue(is_finite($forward));
        self::assertGreaterThanOrEqual(0.0, $forward);
        self::assertLessThanOrEqual(M_PI * HaversineCalculator::MEAN_EARTH_RADIUS_METERS + 1.0e-8, $forward);
        self::assertEqualsWithDelta($forward, $reverse, 1.0e-8);
    }

    public static function representativePairs(): iterable
    {
        yield 'Tehran to Karaj' => [new Coordinate(35.6892, 51.3890), new Coordinate(35.8400, 50.9391)];
        yield 'oblique antipodes susceptible to rounding' => [new Coordinate(-41.81, 0), new Coordinate(41.81, 180)];
        yield 'nearly oblique antipodes' => [new Coordinate(-41.81, 0), new Coordinate(41.81000001, 179.99999999)];
        yield 'south pole to ordinary point' => [new Coordinate(-90, 51), new Coordinate(35, -160)];

        $points = [
            new Coordinate(-90, -180),
            new Coordinate(-89.999999, 179.999999),
            new Coordinate(-45, -95),
            new Coordinate(0, 0),
            new Coordinate(25, 179),
            new Coordinate(89.999999, -179.999999),
            new Coordinate(90, 80),
        ];

        foreach ($points as $originIndex => $origin) {
            foreach ($points as $destinationIndex => $destination) {
                yield 'grid '.$originIndex.' '.$destinationIndex => [$origin, $destination];
            }
        }
    }

    public function test_public_api_uses_the_same_calculator_result(): void
    {
        $origin = new Coordinate(35.6892, 51.3890);
        $destination = new Coordinate(35.8400, 50.9391);
        $direct = (new HaversineCalculator())->calculate($origin, $destination);
        $public = Geo::distance($origin, $destination);

        self::assertInstanceOf(Distance::class, $public);
        self::assertSame($direct->in(Unit::Meter), $public->in(Unit::Meter));
    }
}
