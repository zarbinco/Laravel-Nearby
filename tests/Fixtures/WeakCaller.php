<?php

namespace Zarbinco\Nearby\Tests\Fixtures;

use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Unit;

// This file deliberately uses PHP's default weak typing at the call sites.
final class WeakCaller
{
    public static function coordinate(mixed $latitude, mixed $longitude): Coordinate
    {
        return new Coordinate($latitude, $longitude);
    }

    public static function distance(mixed $value, ?string $factory = null): Distance
    {
        if ($factory === null) {
            return Distance::from($value, Unit::Meter);
        }

        return Distance::$factory($value);
    }
}
