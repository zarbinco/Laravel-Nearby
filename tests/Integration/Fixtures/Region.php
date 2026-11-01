<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Region extends Model
{
    protected $table = 'nearby_test_regions';

    public $timestamps = false;

    protected $guarded = [];

    public function branches(): HasMany
    {
        return $this->hasMany(RelatedBranch::class, 'region_id');
    }
}
