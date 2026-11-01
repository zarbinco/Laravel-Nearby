<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Calculators;

use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;

final class HaversineCalculator
{
    public const float MEAN_EARTH_RADIUS_METERS = 6_371_008.8;

    public function calculate(Coordinate $origin, Coordinate $destination): Distance
    {
        $latitudeDifference = deg2rad($destination->latitude - $origin->latitude);
        $longitudeDifference = $destination->longitude - $origin->longitude;

        // Subtract from the antimeridian first to retain precision for nearby points.
        if ($longitudeDifference > 180.0) {
            $longitudeDifference = ($destination->longitude - 180.0) - ($origin->longitude + 180.0);
        } elseif ($longitudeDifference < -180.0) {
            $longitudeDifference = ($destination->longitude + 180.0) - ($origin->longitude - 180.0);
        }

        $longitudeDifference = deg2rad($longitudeDifference);

        // Co-latitudes retain precision near the poles and give exact zero at a pole.
        $originCosine = sin(deg2rad(90.0 - abs($origin->latitude)));
        $destinationCosine = sin(deg2rad(90.0 - abs($destination->latitude)));

        $a = sin($latitudeDifference / 2.0) ** 2
            + $originCosine * $destinationCosine * sin($longitudeDifference / 2.0) ** 2;
        $a = max(0.0, min(1.0, $a));

        $centralAngle = 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));

        return Distance::fromMeters(self::MEAN_EARTH_RADIUS_METERS * $centralAngle);
    }
}
