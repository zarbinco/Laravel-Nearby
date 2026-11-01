<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\Nearby\Calculators\HaversineCalculator;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Eloquent\GeographicBoundingBox;

final class GeographicBoundingBoxTest extends TestCase
{
    public function test_an_ordinary_local_search_has_finite_useful_bounds(): void
    {
        $box = GeographicBoundingBox::around(new Coordinate(35, 51), Distance::fromKilometers(5));

        self::assertNotNull($box);
        self::assertLessThan(35.0, $box->minLatitude);
        self::assertGreaterThan(35.0, $box->maxLatitude);
        self::assertLessThan(0.1, $box->maxLatitude - $box->minLatitude);
        self::assertCount(1, $box->longitudeRanges);
        self::assertLessThan(51.0, $box->longitudeRanges[0][0]);
        self::assertGreaterThan(51.0, $box->longitudeRanges[0][1]);
    }

    #[DataProvider('sphericalCaps')]
    public function test_the_candidate_box_contains_direct_geodesic_boundary_and_interior_points(Coordinate $origin, float $meters): void
    {
        $box = GeographicBoundingBox::around($origin, Distance::fromMeters($meters));

        if ($box === null) {
            self::assertTrue(true);

            return;
        }

        self::assertGreaterThanOrEqual(-90.0, $box->minLatitude);
        self::assertLessThanOrEqual(90.0, $box->maxLatitude);

        foreach ([0.0, 0.25, 0.999999, 1.0] as $fraction) {
            for ($bearing = 0; $bearing < 360; $bearing += 3) {
                $point = self::destination($origin, $meters * $fraction, (float) $bearing);
                self::assertGreaterThanOrEqual($box->minLatitude, $point->latitude);
                self::assertLessThanOrEqual($box->maxLatitude, $point->latitude);

                $longitudeFits = $box->longitudeRanges === [];
                foreach ($box->longitudeRanges as [$min, $max]) {
                    $longitudeFits = $longitudeFits || ($point->longitude >= $min && $point->longitude <= $max);
                    if ($point->longitude === 180.0) {
                        $longitudeFits = $longitudeFits || (-180.0 >= $min && -180.0 <= $max);
                    }
                }
                self::assertTrue($longitudeFits, 'Longitude outside the conservative candidate ranges.');
            }
        }
    }

    public static function sphericalCaps(): iterable
    {
        yield 'ordinary cap' => [new Coordinate(35.6892, 51.389), 5000.0];
        yield 'southern cap' => [new Coordinate(-60, 20), 250000.0];
        yield 'east antimeridian' => [new Coordinate(20, 179.9), 50000.0];
        yield 'west antimeridian' => [new Coordinate(-20, -179.9), 50000.0];
        yield 'north pole' => [new Coordinate(90, 40), 5000.0];
        yield 'south pole' => [new Coordinate(-90, -40), 5000.0];
        yield 'touching north pole' => [new Coordinate(80, 30), HaversineCalculator::MEAN_EARTH_RADIUS_METERS * M_PI / 18.0];
        yield 'containing north pole' => [new Coordinate(80, 30), 1500000.0];
        yield 'containing south pole' => [new Coordinate(-80, -30), 1500000.0];
        yield 'large cap' => [new Coordinate(30, 50), 15000000.0];
        yield 'tiny radius' => [new Coordinate(35, 51), 1.0e-12];
        yield 'subnormal intermediate radius' => [new Coordinate(0, 180), 1.0e-150];
    }

    public function test_wrapped_caps_have_separate_longitude_intervals(): void
    {
        $box = GeographicBoundingBox::around(new Coordinate(0, 179.9), Distance::fromKilometers(50));

        self::assertNotNull($box);
        self::assertCount(2, $box->longitudeRanges);
        $ranges = $box->longitudeRanges;
        usort($ranges, static fn (array $first, array $second): int => $first[0] <=> $second[0]);
        self::assertSame(-180.0, $ranges[0][0]);
        self::assertSame(180.0, $ranges[1][1]);
    }

    #[DataProvider('polarCaps')]
    public function test_caps_reaching_either_pole_do_not_restrict_longitude(Coordinate $origin, float $meters): void
    {
        $box = GeographicBoundingBox::around($origin, Distance::fromMeters($meters));

        self::assertNotNull($box);
        self::assertSame([], $box->longitudeRanges);
    }

    public static function polarCaps(): iterable
    {
        yield 'north pole origin' => [new Coordinate(90, 20), 1.0];
        yield 'south pole origin' => [new Coordinate(-90, -20), 1.0];
        yield 'north pole boundary' => [new Coordinate(80, 20), HaversineCalculator::MEAN_EARTH_RADIUS_METERS * M_PI / 18.0];
        yield 'south pole boundary' => [new Coordinate(-80, -20), HaversineCalculator::MEAN_EARTH_RADIUS_METERS * M_PI / 18.0];
    }

    public function test_zero_and_global_searches_do_not_add_a_restrictive_box(): void
    {
        $origin = new Coordinate(25, 180);
        self::assertNull(GeographicBoundingBox::around($origin, Distance::fromMeters(0)));
        self::assertNull(GeographicBoundingBox::around($origin, Distance::fromMeters(M_PI * HaversineCalculator::MEAN_EARTH_RADIUS_METERS)));
        self::assertNull(GeographicBoundingBox::around($origin, Distance::fromMeters(PHP_FLOAT_MAX)));
    }

    private static function destination(Coordinate $origin, float $meters, float $bearing): Coordinate
    {
        $latitude = deg2rad($origin->latitude);
        $longitude = deg2rad($origin->longitude);
        $angle = $meters / HaversineCalculator::MEAN_EARTH_RADIUS_METERS;
        $azimuth = deg2rad($bearing);
        $destinationLatitude = asin(max(-1.0, min(1.0,
            sin($latitude) * cos($angle) + cos($latitude) * sin($angle) * cos($azimuth),
        )));
        $destinationLongitude = $longitude + atan2(
            sin($azimuth) * sin($angle) * cos($latitude),
            cos($angle) - sin($latitude) * sin($destinationLatitude),
        );
        $degrees = rad2deg($destinationLongitude);
        if ($degrees > 180.0) {
            $degrees -= 360.0;
        } elseif ($degrees < -180.0) {
            $degrees += 360.0;
        }

        return new Coordinate(rad2deg($destinationLatitude), $degrees);
    }
}
