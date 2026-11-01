<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests;

use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Exceptions\InvalidCoordinateException;
use Zarbinco\Nearby\Tests\Fixtures\WeakCaller;

final class CoordinateTest extends TestCase
{
    #[DataProvider('validCoordinates')]
    public function test_valid_coordinates_are_stored_as_floats(
        int|float $latitude,
        int|float $longitude,
        float $expectedLatitude,
        float $expectedLongitude,
    ): void {
        $coordinate = new Coordinate($latitude, $longitude);

        self::assertSame($expectedLatitude, $coordinate->latitude);
        self::assertSame($expectedLongitude, $coordinate->longitude);
    }

    public static function validCoordinates(): iterable
    {
        yield 'integers' => [35, 51, 35.0, 51.0];
        yield 'floats' => [35.6892, 51.3890, 35.6892, 51.3890];
        yield 'zero' => [0, 0, 0.0, 0.0];
        yield 'minimum latitude' => [-90, 0, -90.0, 0.0];
        yield 'maximum latitude' => [90, 0, 90.0, 0.0];
        yield 'minimum longitude is canonicalized' => [0, -180, 0.0, 180.0];
        yield 'maximum longitude' => [0, 180, 0.0, 180.0];
        yield 'floating minimum longitude' => [0.0, -180.0, 0.0, 180.0];
    }

    #[DataProvider('invalidCoordinateValues')]
    public function test_invalid_latitude_is_rejected(mixed $value): void
    {
        $this->expectException(InvalidCoordinateException::class);
        $this->expectExceptionMessageMatches('/latitude/i');

        new Coordinate($value, 0);
    }

    #[DataProvider('invalidCoordinateValues')]
    public function test_invalid_longitude_is_rejected(mixed $value): void
    {
        $this->expectException(InvalidCoordinateException::class);
        $this->expectExceptionMessageMatches('/longitude/i');

        new Coordinate(0, $value);
    }

    public static function invalidCoordinateValues(): iterable
    {
        yield 'null' => [null];
        yield 'numeric string' => ['35.5'];
        yield 'nonnumeric string' => ['north'];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'array' => [[]];
        yield 'object' => [new stdClass()];
        yield 'NaN' => [NAN];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
    }

    #[DataProvider('outOfRangeCoordinates')]
    public function test_out_of_range_values_are_rejected(float $latitude, float $longitude, string $argument): void
    {
        $this->expectException(InvalidCoordinateException::class);
        $this->expectExceptionMessageMatches('/'.$argument.'/i');

        new Coordinate($latitude, $longitude);
    }

    public static function outOfRangeCoordinates(): iterable
    {
        yield 'below latitude minimum' => [-90.000001, 0.0, 'latitude'];
        yield 'above latitude maximum' => [90.000001, 0.0, 'latitude'];
        yield 'below longitude minimum' => [0.0, -180.000001, 'longitude'];
        yield 'above longitude maximum' => [0.0, 180.000001, 'longitude'];
    }

    #[DataProvider('outOfRangeCoordinates')]
    public function test_boundary_error_messages_preserve_the_invalid_value(
        float $latitude,
        float $longitude,
        string $argument,
    ): void {
        try {
            new Coordinate($latitude, $longitude);
            self::fail('An out-of-range coordinate must be rejected.');
        } catch (InvalidCoordinateException $exception) {
            self::assertSame(1, preg_match('/inclusive; (.+) given\.$/', $exception->getMessage(), $matches));
            self::assertSame($argument === 'latitude' ? $latitude : $longitude, (float) $matches[1]);
        }
    }

    #[DataProvider('weakInvalidScalars')]
    public function test_weakly_typed_callers_cannot_coerce_latitude(mixed $value): void
    {
        $this->expectException(InvalidCoordinateException::class);

        WeakCaller::coordinate($value, 0);
    }

    #[DataProvider('weakInvalidScalars')]
    public function test_weakly_typed_callers_cannot_coerce_longitude(mixed $value): void
    {
        $this->expectException(InvalidCoordinateException::class);

        WeakCaller::coordinate(0, $value);
    }

    public static function weakInvalidScalars(): iterable
    {
        yield 'numeric string' => ['0'];
        yield 'true' => [true];
        yield 'false' => [false];
    }

    #[DataProvider('coordinateProperties')]
    public function test_coordinates_cannot_be_modified(string $property): void
    {
        $coordinate = new Coordinate(35, 51);
        $this->expectException(Error::class);

        $coordinate->$property = 0.0;
    }

    public static function coordinateProperties(): iterable
    {
        yield 'latitude' => ['latitude'];
        yield 'longitude' => ['longitude'];
    }

    public function test_coordinate_properties_cannot_be_unset(): void
    {
        $coordinate = new Coordinate(35, 51);
        $this->expectException(Error::class);

        unset($coordinate->latitude);
    }

    public function test_coordinate_exception_is_an_invalid_argument_exception(): void
    {
        self::assertTrue(is_subclass_of(InvalidCoordinateException::class, InvalidArgumentException::class));
    }
}
