<?php

declare(strict_types=1);

namespace Zarbinco\Nearby;

use Zarbinco\Nearby\Exceptions\InvalidCoordinateException;

final readonly class Bounds
{
    private const float MAX_VIEWPORT_LONGITUDE = 9_007_199_254_740_991.0;

    public float $south;

    public float $west;

    public float $north;

    public float $east;

    public function __construct(mixed $south, mixed $west, mixed $north, mixed $east)
    {
        $south = self::validateRange($south, 'South latitude', 90.0);
        $west = self::validateRange($west, 'West longitude', 180.0);
        $north = self::validateRange($north, 'North latitude', 90.0);
        $east = self::validateRange($east, 'East longitude', 180.0);

        if ($south > $north) {
            throw new InvalidCoordinateException('South latitude must not exceed north latitude.');
        }

        $this->south = $south;
        $this->west = $west;
        $this->north = $north;
        $this->east = $east;
    }

    public static function fromViewport(mixed $south, mixed $west, mixed $north, mixed $east): self
    {
        $west = self::validateViewportLongitude($west, 'West longitude');
        $east = self::validateViewportLongitude($east, 'East longitude');

        if ($east < $west) {
            throw new InvalidCoordinateException('Viewport east longitude must be greater than or equal to west longitude.');
        }

        if (self::coversWorld($west, $east)) {
            return new self($south, -180.0, $north, 180.0);
        }

        $normalizedWest = self::normalizeLongitude($west);
        $normalizedEast = self::normalizeLongitude($east);

        if ($east > $west && $normalizedWest === $normalizedEast) {
            throw new InvalidCoordinateException('Viewport longitude extent cannot be normalized without losing precision.');
        }

        return new self($south, $normalizedWest, $north, $normalizedEast);
    }

    public function contains(Coordinate $coordinate): bool
    {
        if ($coordinate->latitude < $this->south || $coordinate->latitude > $this->north) {
            return false;
        }

        $longitude = $coordinate->longitude;

        // Coordinate stores either signed antimeridian as +180.
        if ($longitude === 180.0 && ($this->west === -180.0 || $this->east === -180.0)) {
            return true;
        }

        if ($this->crossesAntimeridian()) {
            return $longitude >= $this->west || $longitude <= $this->east;
        }

        return $longitude >= $this->west && $longitude <= $this->east;
    }

    public function crossesAntimeridian(): bool
    {
        return $this->west > $this->east;
    }

    private static function validateNumber(mixed $value, string $name): int|float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidCoordinateException(sprintf(
                '%s must be an integer or float; %s given.',
                $name,
                get_debug_type($value),
            ));
        }

        if (is_float($value) && !is_finite($value)) {
            throw new InvalidCoordinateException($name.' must be finite.');
        }

        return $value;
    }

    private static function validateRange(mixed $value, string $name, float $limit): float
    {
        $value = self::validateNumber($value, $name);

        if ($value < -$limit || $value > $limit) {
            throw new InvalidCoordinateException(sprintf(
                '%s must be between %g and %g degrees, inclusive; %.17g given.',
                $name,
                -$limit,
                $limit,
                $value,
            ));
        }

        return (float) $value;
    }

    private static function validateViewportLongitude(mixed $value, string $name): float
    {
        $value = self::validateNumber($value, $name);

        // Check integers before conversion: beyond binary64's safe integer range,
        // a map offset may already lose whole degrees when stored as a float.
        if ($value < -self::MAX_VIEWPORT_LONGITUDE || $value > self::MAX_VIEWPORT_LONGITUDE) {
            throw new InvalidCoordinateException($name.' exceeds the safe viewport magnitude of 9007199254740991 degrees.');
        }

        return (float) $value;
    }

    private static function coversWorld(float $west, float $east): bool
    {
        $span = $east - $west;

        if ($span !== 360.0) {
            return $span > 360.0;
        }

        // TwoDiff recovers the residual when subtraction rounds a span to 360.
        // A slightly smaller viewport must keep its gap instead of becoming global.
        $virtualWest = $east - $span;
        $virtualEast = $span + $virtualWest;
        $residual = ($east - $virtualEast) + ($virtualWest - $west);

        return $residual >= 0.0;
    }

    private static function normalizeLongitude(float $value): float
    {
        $value = fmod($value, 360.0);

        // Shifting by 180 before reduction would erase small boundary values.
        if ($value > 180.0) {
            return $value - 360.0;
        }

        if ($value < -180.0) {
            return $value + 360.0;
        }

        return $value;
    }
}
