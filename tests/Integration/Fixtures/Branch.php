<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\Nearby\Eloquent\Concerns\HasNearbyCoordinates;

class Branch extends Model
{
    use HasNearbyCoordinates;

    protected $table = 'nearby_test_branches';

    public $timestamps = false;

    protected $guarded = [];
}
