<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

use Illuminate\Database\Eloquent\Casts\Attribute;

final class AppendedBranch extends Branch
{
    protected $appends = ['display_name', 'nearby_distance_meters'];

    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => strtoupper($this->name));
    }
}
