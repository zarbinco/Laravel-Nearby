<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Region;
use App\Models\Warehouse;
use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Geo;
use Zarbinco\Nearby\Unit;

require __DIR__.'/vendor/autoload.php';

if (!in_array('--run', $argv, true) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*_consumer\z/', getenv('DB_DATABASE') ?: '') !== 1) {
    throw new RuntimeException('Consumer schema checks require --run and a dedicated DB_DATABASE ending in _consumer.');
}

$app = require __DIR__.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
$version = (string) DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

if (stripos($version, 'MariaDB') !== false || preg_match('/\A8\.4\./', $version) !== 1) {
    throw new RuntimeException('Consumer checks require real MySQL 8.4.');
}

$assertions = 0;
$check = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;

    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$keys = static fn ($models): array => $models->modelKeys();
$origin = new Coordinate(0, 0);
$radius = Distance::fromKilometers(5);
$check(!InstalledVersions::isInstalled('orchestra/testbench'), 'The consumer must run without Testbench.');
$check(is_float(Geo::distance($origin, new Coordinate(0, 1))->in(Unit::Meter)), 'The standalone calculation API must autoload.');

try {
    foreach (['nearby_consumer_branches', 'nearby_consumer_warehouses', 'nearby_consumer_regions'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('nearby_consumer_regions', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    Schema::create('nearby_consumer_branches', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->double('latitude')->nullable();
        $table->double('longitude')->nullable();
        $table->boolean('is_active');
        $table->unsignedInteger('tenant_id');
        $table->unsignedBigInteger('region_id');
        $table->softDeletes();
    });
    Schema::create('nearby_consumer_warehouses', static function (Blueprint $table): void {
        $table->string('code')->primary();
        $table->decimal('lat', 18, 12)->nullable();
        $table->decimal('lng', 18, 12)->nullable();
    });
    $north = Region::create(['name' => 'North']);
    $south = Region::create(['name' => 'South']);
    $seed = static fn (string $name, ?float $longitude, bool $active = true, int $tenant = 1, ?Region $region = null): Branch => Branch::create([
        'name' => $name, 'latitude' => $longitude === null ? null : 0, 'longitude' => $longitude,
        'is_active' => $active, 'tenant_id' => $tenant, 'region_id' => ($region ?? $north)->id,
    ]);
    $here = $seed('Origin', 0);
    $near = $seed('Near', 0.01);
    $band = $seed('Band', 0.03, region: $south);
    $far = $seed('Far', 0.08, region: $south);
    $inactive = $seed('Inactive', 0.005, false);
    $otherTenant = $seed('Other tenant', 0, tenant: 2);
    $missing = $seed('Missing', null);
    $invalidCoordinates = $seed('Invalid longitude', 181);
    $deleted = $seed('Deleted', 0.002);
    $deleted->delete();
    Warehouse::create(['code' => 'warehouse-a', 'lat' => 0, 'lng' => 0.01]);

    $expectedNearest = [$here->id, $inactive->id, $near->id, $band->id, $far->id];
    $nearest = Branch::query()->nearestTo($origin, limit: 10)->get();
    $check($keys($nearest) === $expectedNearest, 'Nearest queries must preserve tenant scopes, soft deletes, and distance ordering.');
    $check($nearest->first() instanceof Branch, 'Nearby results must retain their model identity.');
    $check($keys(Branch::query()->withinRadius($origin, $radius)->orderBy('id')->get()) === [$here->id, $near->id, $band->id, $inactive->id], 'Radius queries must include all eligible nearby branches.');
    $active = Branch::query()->where('is_active', true)->withinRadius($origin, $radius)->nearestTo($origin, limit: 10)->get();
    $check($keys($active) === [$here->id, $near->id, $band->id], 'Bounded nearest queries must preserve application conditions.');
    $check($keys(Branch::query()->betweenDistances($origin, Distance::fromKilometers(2), Distance::fromKilometers(10))->orderByDistance($origin)->get()) === [$band->id, $far->id], 'Distance bands must execute in the real application.');
    $check($keys(Branch::query()->orderByDistance($origin, direction: 'desc')->get()) === array_reverse($expectedNearest), 'Descending distance ordering must remain consistent.');
    $check($keys(Branch::query()->orderBy('name', 'desc')->nearestTo($origin)->get()) === $expectedNearest, 'Nearest ordering must replace preceding application ordering.');
    $check($keys(Branch::query()->withoutGlobalScope('tenant')->nearestTo($origin)->get())
        === [$here->id, $otherTenant->id, $inactive->id, $near->id, $band->id, $far->id], 'Explicitly removing the tenant scope before a proximity query must preserve the remaining model scopes.');
    $check(Branch::query()->withinRadius($origin, $radius)->count() === 4, 'Radius counts must match eligible records.');
    $check(Branch::query()->withinRadius($origin, Distance::fromMeters(0))->exists(), 'Zero-radius existence checks must work.');

    $selected = Branch::query()->select(['nearby_consumer_branches.id', 'nearby_consumer_branches.name'])
        ->whereKey($near->id)->withDistance($origin)->firstOrFail();
    $check(is_float($selected->nearby_distance_meters), 'Computed distance must be a PHP float.');
    $check(abs($selected->nearbyDistance()->in(Unit::Meter) - Geo::distance($origin, new Coordinate(0, 0.01))->in(Unit::Meter)) < 1.0e-6, 'Database and standalone distances must agree.');
    $check($selected->nearbyDistance()->in(Unit::Mile) > 0, 'Computed results must support unit conversion.');
    $boundary = Distance::fromMeters($selected->nearby_distance_meters);
    $check(Branch::query()->whereKey($near->id)->withinRadius($origin, $boundary)->exists(), 'An unrounded database distance must be included at the exact radius boundary.');
    $check(Branch::query()->whereKey($near->id)->betweenDistances($origin, min: $boundary, max: $boundary)->count() === 1, 'Equal distance-band boundaries must include the exact database distance.');
    $check(!array_key_exists('nearby_distance_meters', $selected->getAttributes()), 'The computed distance must remain outside persisted attributes.');
    $check(array_key_exists('nearby_distance_meters', $selected->toArray()), 'Distance-loaded models must serialize the computed field.');
    $check(!str_contains($selected->toJson(), '__nearby_'), 'Internal aliases must not leak into JSON.');
    $check(!array_key_exists('nearby_distance_meters', (clone $selected)->makeHidden('nearby_distance_meters')->toArray()), 'Hidden attributes must remain respected.');
    $readOnly = false;

    try {
        $selected->nearby_distance_meters = 1;
    } catch (LogicException) {
        $readOnly = true;
    }

    $check($readOnly, 'Computed distances must remain read-only.');
    $selected->name = 'Near saved';
    $check($selected->saveQuietly(), 'Saving a selected model must not persist computed distance.');
    $check(Branch::query()->findOrFail($near->id)->name === 'Near saved', 'Model changes must persist normally.');
    $check(!Schema::hasColumn('nearby_consumer_branches', 'nearby_distance_meters'), 'No computed-distance database column should be required.');
    $ordinary = Branch::query()->findOrFail($here->id);
    $check($ordinary->nearbyDistance() === null, 'An ordinary model must have no calculated distance.');
    $check(!array_key_exists('nearby_distance_meters', $ordinary->toArray()), 'Ordinary model serialization must remain unchanged.');
    $invalid = Branch::query()->whereKey($missing->id)->withDistance($origin)->firstOrFail();
    $check($invalid->nearby_distance_meters === null, 'Missing coordinates must produce a null computed distance.');
    $invalid = Branch::query()->whereKey($invalidCoordinates->id)->withDistance($origin)->firstOrFail();
    $check($invalid->nearby_distance_meters === null, 'Out-of-range stored coordinates must produce a null computed distance.');
    $check($invalid->nearbyDistance() === null, 'Out-of-range stored coordinates must not produce a distance value object.');

    $warehouse = Warehouse::query()->nearestTo($origin)->firstOrFail();
    $check($warehouse->code === 'warehouse-a' && is_float($warehouse->nearby_distance_meters), 'Custom DECIMAL coordinate columns and string keys must work.');
    $related = Branch::query()->with('region')->whereHas('region', static fn ($query) => $query->where('name', 'North'))->withinRadius($origin, $radius)->nearestTo($origin)->get();
    $check($keys($related) === [$here->id, $inactive->id, $near->id], 'Basic relationship filters must compose with geographic scopes.');
    $check($related->first()->relationLoaded('region') && $related->first()->region->name === 'North', 'Eager loading must remain intact.');

    $pageQuery = Branch::query()->where('is_active', true)->withinRadius($origin, $radius)->withDistance($origin)->orderByDistance($origin);
    $page = (clone $pageQuery)->paginate(2, ['*'], 'page', 1);
    $check($page->total() === 3 && $page->count() === 2, 'Pagination counts and page sizes must remain correct.');
    $check(is_float($page->items()[0]->nearby_distance_meters), 'Paginated models must hydrate their distance.');
    $check((clone $pageQuery)->simplePaginate(2)->hasMorePages(), 'Simple pagination must return normal pagination metadata.');
    $reused = Branch::query()->withinRadius($origin, $radius)->nearestTo($origin);
    $check($keys($reused->get()) === $keys($reused->get()), 'Reusing a builder must preserve query behavior.');
    $check((clone $reused)->where('is_active', true)->get()->count() === 3, 'Cloned builders must preserve scopes and bindings.');
    $repeated = Branch::query()->whereKey($near->id)->withDistance($origin)->withinRadius($origin, $radius)
        ->withDistance(new Coordinate(0, 0.01))->orderByDistance($origin)->firstOrFail();
    $check($repeated->nearby_distance_meters === 0.0, 'Repeated distance selections must expose the last supplied origin.');

    $kernel = $app->make(HttpKernel::class);

    foreach ([[1, 3], [2, 1], [1, 3]] as [$tenant, $expectedCount]) {
        $request = Request::create('/branches?tenant='.$tenant, 'GET');
        $response = $kernel->handle($request);
        $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $check($response->getStatusCode() === 200 && count($data['branches'] ?? []) === $expectedCount, 'Repeated HTTP JSON responses must preserve tenant isolation.');
        $jsonDistance = $data['branches'][0]['nearby_distance_meters'] ?? null;
        $check(isset($data['branches'][0]['region']) && (is_int($jsonDistance) || is_float($jsonDistance)), 'HTTP responses must contain related models and numeric distances.');
        $check(!str_contains($response->getContent(), '__nearby_'), 'Internal aliases must not leak into HTTP JSON.');
        $kernel->terminate($request, $response);
    }

    $request = Request::create('/ordinary', 'GET');
    $response = $kernel->handle($request);
    $check($response->getStatusCode() === 200 && !array_key_exists('nearby_distance_meters', json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)), 'Ordinary HTTP model responses must omit an unavailable distance.');
    $kernel->terminate($request, $response);
    $request = Request::create('/warehouses', 'GET');
    $response = $kernel->handle($request);
    $check($response->getStatusCode() === 200 && json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)[0]['code'] === 'warehouse-a', 'Custom-column models must serialize through the HTTP kernel.');
    $kernel->terminate($request, $response);

    $previousPdo = DB::connection()->getPdo();
    DB::purge();
    $check(DB::connection()->getPdo() !== $previousPdo, 'Purging the connection must replace the physical PDO.');
    $check($keys(Branch::query()->nearestTo($origin)->get()) === $expectedNearest, 'Queries must work after a real database reconnection.');
    echo json_encode([
        'completed' => true, 'assertions' => $assertions, 'php' => PHP_VERSION,
        'laravel' => $app->version(), 'mysql' => $version,
        'checks' => ['composer_autoload', 'standalone_core', 'eloquent_scopes', 'custom_decimal_columns', 'relationships',
            'tenant_global_scope', 'soft_deletes', 'pagination', 'model_saving', 'serialization', 'repeated_http_requests', 'pdo_reconnection'],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} finally {
    foreach (['nearby_consumer_branches', 'nearby_consumer_warehouses', 'nearby_consumer_regions'] as $table) {
        Schema::dropIfExists($table);
    }
}
