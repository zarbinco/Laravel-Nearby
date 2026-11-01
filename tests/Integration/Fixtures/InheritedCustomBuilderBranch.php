<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

use Zarbinco\Nearby\Eloquent\Concerns\HasNearbyCoordinates;

final class InheritedCustomBuilderBranch extends CustomFactoryBaseModel
{
    use HasNearbyCoordinates;

    protected $table = 'nearby_test_branches';

    public $timestamps = false;

    protected $guarded = [];
}
