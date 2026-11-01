<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Zarbinco\Nearby\Eloquent\Concerns\HasNearbyCoordinates;

class Branch extends Model
{
    use HasNearbyCoordinates;
    use SoftDeletes;

    protected $table = 'nearby_consumer_branches';

    protected $guarded = [];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', static function (Builder $query): void {
            $query->where($query->getModel()->qualifyColumn('tenant_id'), (int) config('consumer.tenant_id', 1));
        });
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
