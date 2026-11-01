<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Unit;

final class UnitTest extends TestCase
{
    #[DataProvider('unitDefinitions')]
    public function test_unit_abbreviations_and_defined_meter_factors(Unit $unit, string $abbreviation, float $meters): void
    {
        self::assertSame($abbreviation, $unit->value);
        self::assertSame($unit, Unit::from($abbreviation));
        self::assertSame($meters, $unit->metersPerUnit());
        self::assertSame($meters, Distance::from(1, $unit)->in(Unit::Meter));
    }

    public static function unitDefinitions(): iterable
    {
        yield 'meter' => [Unit::Meter, 'm', 1.0];
        yield 'kilometer' => [Unit::Kilometer, 'km', 1000.0];
        yield 'international mile' => [Unit::Mile, 'mi', 1609.344];
        yield 'international foot' => [Unit::Foot, 'ft', 0.3048];
        yield 'international yard' => [Unit::Yard, 'yd', 0.9144];
        yield 'nautical mile' => [Unit::NauticalMile, 'nmi', 1852.0];
    }

    #[DataProvider('conversionExamples')]
    public function test_conversions(float $value, Unit $source, Unit $target, float $expected): void
    {
        self::assertEqualsWithDelta($expected, Distance::from($value, $source)->in($target), 1.0e-12);
    }

    public static function conversionExamples(): iterable
    {
        yield 'meter to kilometer' => [1500.0, Unit::Meter, Unit::Kilometer, 1.5];
        yield 'kilometer to meter' => [1.5, Unit::Kilometer, Unit::Meter, 1500.0];
        yield 'kilometer to mile' => [1.609344, Unit::Kilometer, Unit::Mile, 1.0];
        yield 'mile to kilometer' => [1.0, Unit::Mile, Unit::Kilometer, 1.609344];
        yield 'meter to foot' => [0.3048, Unit::Meter, Unit::Foot, 1.0];
        yield 'meter to yard' => [0.9144, Unit::Meter, Unit::Yard, 1.0];
        yield 'nautical mile to meter' => [2.0, Unit::NauticalMile, Unit::Meter, 3704.0];
        yield 'international mile to feet' => [1.0, Unit::Mile, Unit::Foot, 5280.0];
        yield 'international mile to yards' => [1.0, Unit::Mile, Unit::Yard, 1760.0];
    }

    #[DataProvider('unitPairs')]
    public function test_round_trip_conversion_preserves_precision(Unit $source, Unit $target): void
    {
        $original = 123.456789012345;
        $converted = Distance::from($original, $source)->in($target);
        $roundTrip = Distance::from($converted, $target)->in($source);

        self::assertEqualsWithDelta($original, $roundTrip, 1.0e-12);
    }

    public static function unitPairs(): iterable
    {
        foreach (Unit::cases() as $source) {
            foreach (Unit::cases() as $target) {
                yield $source->value.' to '.$target->value => [$source, $target];
            }
        }
    }

    public function test_conversion_does_not_round_display_values(): void
    {
        self::assertSame(1.0 / 1000.0, Distance::fromMeters(1)->in(Unit::Kilometer));
        self::assertSame(1.0 / 1609.344, Distance::fromMeters(1)->in(Unit::Mile));
    }
}
