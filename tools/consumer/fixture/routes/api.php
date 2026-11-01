<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;

Route::get('/branches', static function (Request $request) {
    $tenant = $request->integer('tenant', 1);
    config(['consumer.tenant_id' => $tenant]);

    return response()->json([
        'tenant' => $tenant,
        'branches' => Branch::query()->with('region')->where('is_active', true)
            ->withinRadius(new Coordinate(0, 0), Distance::fromKilometers(5))
            ->nearestTo(new Coordinate(0, 0), limit: 10)->get(),
    ]);
});

Route::get('/ordinary', static function () {
    config(['consumer.tenant_id' => 1]);

    return response()->json(Branch::query()->firstOrFail());
});

Route::get('/warehouses', static fn () => response()->json(Warehouse::query()->nearestTo(new Coordinate(0, 0))->get()));
