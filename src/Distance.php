<?php

declare(strict_types=1);

namespace Zarbinco\Nearby;

use Zarbinco\Nearby\Exceptions\InvalidDistanceException;

final readonly class Distance
{
    private function __construct(private float $meters) {}

    public static function from(mixed $value, Unit $unit): self
    {
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidDistanceException(sprintf(
                'Distance must be an integer or float; %s given.',
                get_debug_type($value),
            ));
        }

        $value = (float) $value;

        if (!is_finite($value) || $value < 0.0) {
            throw new InvalidDistanceException('Distance must be finite and nonnegative.');
        }

        $meters = $value * $unit->metersPerUnit();

        if (!is_finite($meters)) {
            throw new InvalidDistanceException(sprintf(
                'Distance in %s is too large to represent in meters.',
                $unit->value,
            ));
        }

        return new self($meters);
    }

    public static function fromMeters(mixed $value): self
    {
        return self::from($value, Unit::Meter);
    }

    public static function fromKilometers(mixed $value): self
    {
        return self::from($value, Unit::Kilometer);
    }

    public static function fromMiles(mixed $value): self
    {
        return self::from($value, Unit::Mile);
    }

    public static function fromFeet(mixed $value): self
    {
        return self::from($value, Unit::Foot);
    }

    public static function fromYards(mixed $value): self
    {
        return self::from($value, Unit::Yard);
    }

    public static function fromNauticalMiles(mixed $value): self
    {
        return self::from($value, Unit::NauticalMile);
    }

    public function in(Unit $unit): float
    {
        $value = $this->meters / $unit->metersPerUnit();

        if (!is_finite($value)) {
            throw new InvalidDistanceException(sprintf(
                'Distance is too large to represent in %s.',
                $unit->value,
            ));
        }

        return $value;
    }
}
