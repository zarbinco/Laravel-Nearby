<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\Nearby\Eloquent\Concerns\HasNearbyCoordinates;

final class CustomBranch extends Model
{
    use HasNearbyCoordinates;

    protected $table = 'nearby_test_custom_branches';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function nearbyLatitudeColumn(): string
    {
        return 'lat';
    }

    protected function nearbyLongitudeColumn(): string
    {
        return 'lng';
    }
}
