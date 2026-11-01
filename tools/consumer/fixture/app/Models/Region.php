<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    protected $table = 'nearby_consumer_regions';

    protected $guarded = [];

    public $timestamps = false;

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
