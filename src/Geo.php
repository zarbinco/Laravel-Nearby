<?php

declare(strict_types=1);

namespace Zarbinco\Nearby;

use Zarbinco\Nearby\Calculators\HaversineCalculator;

final class Geo
{
    public static function distance(Coordinate $origin, Coordinate $destination): Distance
    {
        return (new HaversineCalculator)->calculate($origin, $destination);
    }
}
