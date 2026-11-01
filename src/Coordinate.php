<?php

declare(strict_types=1);

namespace Zarbinco\Nearby;

use Zarbinco\Nearby\Exceptions\InvalidCoordinateException;

final readonly class Coordinate
{
    public float $latitude;

    public float $longitude;

    public function __construct(mixed $latitude, mixed $longitude)
    {
        $this->latitude = self::validate($latitude, 'Latitude', 90.0);

        $longitude = self::validate($longitude, 'Longitude', 180.0);
        $this->longitude = $longitude === -180.0 ? 180.0 : $longitude;
    }

    private static function validate(mixed $value, string $name, float $limit): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidCoordinateException(sprintf(
                '%s must be an integer or float; %s given.',
                $name,
                get_debug_type($value),
            ));
        }

        $value = (float) $value;

        if (!is_finite($value)) {
            throw new InvalidCoordinateException($name.' must be finite.');
        }

        if ($value < -$limit || $value > $limit) {
            throw new InvalidCoordinateException(sprintf(
                '%s must be between %g and %g degrees, inclusive; %.17g given.',
                $name,
                -$limit,
                $limit,
                $value,
            ));
        }

        return $value;
    }
}
