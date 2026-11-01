<?php

declare(strict_types=1);

namespace Zarbinco\Nearby;

enum Unit: string
{
    case Meter = 'm';
    case Kilometer = 'km';
    case Mile = 'mi';
    case Foot = 'ft';
    case Yard = 'yd';
    case NauticalMile = 'nmi';

    public function metersPerUnit(): float
    {
        return match ($this) {
            self::Meter => 1.0,
            self::Kilometer => 1000.0,
            self::Mile => 1609.344,
            self::Foot => 0.3048,
            self::Yard => 0.9144,
            self::NauticalMile => 1852.0,
        };
    }
}
