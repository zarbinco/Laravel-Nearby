<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests;

use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Exceptions\InvalidDistanceException;
use Zarbinco\Nearby\Tests\Fixtures\WeakCaller;
use Zarbinco\Nearby\Unit;

final class DistanceTest extends TestCase
{
    public function test_zero_and_positive_integer_distances_are_accepted(): void
    {
        self::assertSame(0.0, Distance::fromMeters(0)->in(Unit::Meter));
        self::assertSame(500.0, Distance::fromMeters(500)->in(Unit::Meter));
    }

    public function test_fractional_meters_preserve_the_input_precision(): void
    {
        $value = 1.2345678901234567;

        self::assertSame($value, Distance::fromMeters($value)->in(Unit::Meter));
        self::assertSame(1.0e-12, Distance::fromMeters(1.0e-12)->in(Unit::Meter));
    }

    #[DataProvider('factories')]
    public function test_convenience_factories_match_the_general_factory(string $factory, Unit $unit): void
    {
        self::assertSame(
            Distance::from(1.25, $unit)->in(Unit::Meter),
            Distance::$factory(1.25)->in(Unit::Meter),
        );
        self::assertSame(0.0, Distance::$factory(0)->in(Unit::Meter));
    }

    public static function factories(): iterable
    {
        yield 'meters' => ['fromMeters', Unit::Meter];
        yield 'kilometers' => ['fromKilometers', Unit::Kilometer];
        yield 'miles' => ['fromMiles', Unit::Mile];
        yield 'feet' => ['fromFeet', Unit::Foot];
        yield 'yards' => ['fromYards', Unit::Yard];
        yield 'nautical miles' => ['fromNauticalMiles', Unit::NauticalMile];
    }

    #[DataProvider('invalidDistances')]
    public function test_invalid_distances_are_rejected(mixed $value): void
    {
        $this->expectException(InvalidDistanceException::class);

        Distance::from($value, Unit::Meter);
    }

    public static function invalidDistances(): iterable
    {
        yield 'negative integer' => [-1];
        yield 'negative fraction' => [-0.000001];
        yield 'null' => [null];
        yield 'numeric string' => ['5'];
        yield 'nonnumeric string' => ['five'];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'array' => [[]];
        yield 'object' => [new stdClass()];
        yield 'NaN' => [NAN];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
    }

    #[DataProvider('weakInvalidFactoryInputs')]
    public function test_weakly_typed_callers_cannot_coerce_distance_values(?string $factory, mixed $value): void
    {
        $this->expectException(InvalidDistanceException::class);

        WeakCaller::distance($value, $factory);
    }

    public static function weakInvalidFactoryInputs(): iterable
    {
        $factories = [null, 'fromMeters', 'fromKilometers', 'fromMiles', 'fromFeet', 'fromYards', 'fromNauticalMiles'];

        foreach ($factories as $factory) {
            foreach (['5', true, false] as $index => $value) {
                yield ($factory ?? 'from').' '.$index => [$factory, $value];
            }
        }
    }

    #[DataProvider('unitsThatCanOverflowMeters')]
    public function test_overflow_during_creation_is_rejected(Unit $unit): void
    {
        $this->expectException(InvalidDistanceException::class);

        Distance::from(PHP_FLOAT_MAX, $unit);
    }

    public static function unitsThatCanOverflowMeters(): iterable
    {
        yield 'kilometer' => [Unit::Kilometer];
        yield 'mile' => [Unit::Mile];
        yield 'nautical mile' => [Unit::NauticalMile];
    }

    #[DataProvider('unitsThatCanOverflowResults')]
    public function test_overflow_during_conversion_is_rejected(Unit $unit): void
    {
        $distance = Distance::fromMeters(PHP_FLOAT_MAX);
        $this->expectException(InvalidDistanceException::class);

        $distance->in($unit);
    }

    public static function unitsThatCanOverflowResults(): iterable
    {
        yield 'foot' => [Unit::Foot];
        yield 'yard' => [Unit::Yard];
    }

    public function test_large_finite_results_remain_usable(): void
    {
        $distance = Distance::fromMeters(PHP_FLOAT_MAX);

        self::assertSame(PHP_FLOAT_MAX, $distance->in(Unit::Meter));
        self::assertTrue(is_finite($distance->in(Unit::Kilometer)));
        self::assertTrue(is_finite(Distance::fromFeet(PHP_FLOAT_MAX)->in(Unit::Meter)));
    }

    public function test_conversion_does_not_mutate_the_distance(): void
    {
        $distance = Distance::fromKilometers(5);

        $distance->in(Unit::Mile);
        $distance->in(Unit::Foot);

        self::assertSame(5000.0, $distance->in(Unit::Meter));
        self::assertTrue((new ReflectionClass(Distance::class))->isReadOnly());
    }

    public function test_stored_meters_cannot_be_modified_even_through_reflection(): void
    {
        $distance = Distance::fromMeters(5);
        $property = (new ReflectionClass($distance))->getProperty('meters');
        $this->expectException(Error::class);

        $property->setValue($distance, 6.0);
    }

    public function test_distance_exception_is_an_invalid_argument_exception(): void
    {
        self::assertTrue(is_subclass_of(InvalidDistanceException::class, InvalidArgumentException::class));
    }
}
