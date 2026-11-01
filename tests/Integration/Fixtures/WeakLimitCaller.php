<?php

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Zarbinco\Nearby\Coordinate;

// Keep the Eloquent scope invocation in a weakly typed calling file.
final class WeakLimitCaller
{
    public static function nearest(Coordinate $origin, mixed $limit): Builder
    {
        return Branch::query()->nearestTo($origin, $limit);
    }
}
