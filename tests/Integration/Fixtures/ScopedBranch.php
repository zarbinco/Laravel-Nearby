<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ScopedBranch extends Branch
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', static function (Builder $query): void {
            $query->where('nearby_test_branches.tenant_id', 1);
        });
    }
}
