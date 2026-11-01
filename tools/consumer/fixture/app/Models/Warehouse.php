<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\Nearby\Eloquent\Concerns\HasNearbyCoordinates;

class Warehouse extends Model
{
    use HasNearbyCoordinates;

    protected $table = 'nearby_consumer_warehouses';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    protected function nearbyLatitudeColumn(): string
    {
        return 'lat';
    }

    protected function nearbyLongitudeColumn(): string
    {
        return 'lng';
    }
}
