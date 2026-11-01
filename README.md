# Laravel Nearby

Laravel Nearby adds geographic proximity queries to existing Eloquent models and converts distances between common units. Use your model's latitude and longitude columns to find nearby records, search within a radius, and order results by distance.

Its calculation core also works through Composer autoloading alone, without Laravel bootstrapping, a database connection, or an external service.

## Requirements

- PHP 8.3 or later within the PHP 8 series.
- Laravel 13.x, with 13.0 as the minimum version, for Eloquent integration. Laravel 12 and earlier, including older standalone Illuminate components, are rejected by Composer.
- MySQL 8.4.x and the PDO MySQL extension for proximity queries.

The package requires `illuminate/database:^13.0`; Laravel 13 satisfies that dependency through its normal Composer replacement mechanism. The full Laravel framework is a development dependency through Orchestra Testbench, rather than a runtime requirement. Standalone calculations do not require an application or database connection.

CI is configured for standalone tests, real MySQL integration tests, and separate latest/lowest Laravel 13 consumer installations on PHP 8.3, 8.4, and 8.5. Each workflow run shares one resolved development lockfile across its package test jobs; consumer applications resolve their own runtime dependencies. Hosted workflow results are separate from local verification.

Local package tests have run on PHP 8.3.30, 8.4.27, and 8.5.12 with Laravel 13.39.0 and MySQL 8.4.3. Separate consumer applications have verified Laravel 13.30.0 and 13.39.0 without Testbench. These are verified versions, rather than a claim that every Laravel 13 release was tested. Exact Laravel 13.0.0 remains unverified because Composer blocks it for framework security advisories; verification never disables that protection. Other Laravel major versions and database engines are unsupported.

## Installation

For a local checkout, place this package in a directory named `nearby` alongside your application directory. Merge this repository entry into your application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../nearby",
            "options": {
                "versions": {
                    "zarbinco/laravel-nearby": "dev-main"
                }
            }
        }
    ]
}
```

Then run from your application directory:

```bash
composer require "zarbinco/laravel-nearby:dev-main@dev"
```

This installs the local source without relying on a Packagist release or changing your application's global stability setting. Adjust `url` if your checkout is elsewhere. The same setup works in a standalone PHP project.

To run this checkout's examples and tests directly:

```bash
composer install
php examples/distance.php
```

## Quick Start

Save this as a PHP file in the root of a project where the package is installed:

```php
<?php

declare(strict_types=1);

use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Geo;
use Zarbinco\Nearby\Unit;

require __DIR__.'/vendor/autoload.php';

$origin = new Coordinate(latitude: 35.6892, longitude: 51.3890);
$destination = new Coordinate(latitude: 35.8400, longitude: 50.9391);

$distance = Geo::distance($origin, $destination);

printf("%.3f km\n", $distance->in(Unit::Kilometer));
```

This example displays `43.920 km`.

## Eloquent Models

Add `HasNearbyCoordinates` to an existing model:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\Nearby\Eloquent\Concerns\HasNearbyCoordinates;

class Branch extends Model
{
    use HasNearbyCoordinates;
}
```

The defaults are `latitude` and `longitude`, stored in decimal degrees in ordinary numeric `DOUBLE` or `DECIMAL` columns. Existing columns can be used directly; the package does not require migrations, additional tables, or spatial columns. Your model keeps its usual table name, primary key, casts, relationships, global scopes, and soft-delete behavior.

Override the protected column methods when your schema uses different names:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Zarbinco\Nearby\Eloquent\Concerns\HasNearbyCoordinates;

class Warehouse extends Model
{
    use HasNearbyCoordinates;

    protected function nearbyLatitudeColumn(): string
    {
        return 'lat';
    }

    protected function nearbyLongitudeColumn(): string
    {
        return 'lng';
    }
}
```

Configure bare column names in trusted model code. Identifiers must start with a letter or underscore and contain only letters, digits, and underscores. Qualified names, SQL expressions, and user-supplied column names are unsupported. The package qualifies identifiers with the model table and quotes them using Laravel's query grammar. No service provider or facade registration is needed.

## Finding Nearby Records

These examples run inside a Laravel application with the `Branch` model above and its existing database table.

### Nearest Branches

```php
use App\Models\Branch;
use Zarbinco\Nearby\Coordinate;

$origin = new Coordinate(latitude: 35.6892, longitude: 51.3890);

$branches = Branch::query()
    ->where('is_active', true)
    ->nearestTo($origin, limit: 10)
    ->get();
```

`nearestTo()` returns up to the requested number of eligible models, ordered from nearest to farthest, with the model key ascending to break equal-distance ties. It adds the computed distance to each result. The limit defaults to 10 and must be a positive PHP integer; zero, negative values, numeric strings, floats, and booleans are rejected.

There is no implicit maximum radius. The nearest record can be far away; fewer than ten eligible records produce fewer than ten results. Existing filters remain in effect. `nearestTo()` clears earlier ordering so distance is the primary ordering; ordering added afterward is appended to the resulting distance and key order.

### Within a Radius

```php
use App\Models\Branch;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;

$origin = new Coordinate(latitude: 35.6892, longitude: 51.3890);
$radius = Distance::fromKilometers(5);

$branches = Branch::query()
    ->where('is_active', true)
    ->withinRadius($origin, $radius)
    ->get();

$nearestBranches = Branch::query()
    ->where('is_active', true)
    ->withinRadius($origin, $radius)
    ->nearestTo($origin, limit: 10)
    ->get();
```

Radius filtering includes the boundary. Combining it with `nearestTo()` returns at most ten matching records inside that radius; it never expands the radius to fill the limit. `withinRadius()` alone does not select the computed distance or change ordering.

A zero radius includes coincident coordinates, duplicate locations, equivalent -180/+180 longitudes, and the same pole regardless of longitude. It does not apply a tolerance that turns nearby distinct locations into coincident points.

### Between Distances

```php
use App\Models\Branch;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;

$origin = new Coordinate(latitude: 35.6892, longitude: 51.3890);

$branches = Branch::query()
    ->betweenDistances(
        $origin,
        min: Distance::fromKilometers(2),
        max: Distance::fromKilometers(10),
    )
    ->orderByDistance($origin)
    ->get();
```

Both boundaries are inclusive. Equal minimum and maximum distances are allowed, as is a minimum of zero. A minimum greater than the maximum raises `InvalidDistanceException`; values are never silently swapped.

### Ordering and Reading Distances

```php
use App\Models\Branch;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Unit;

$origin = new Coordinate(latitude: 35.6892, longitude: 51.3890);

$branches = Branch::query()
    ->withDistance($origin)
    ->orderByDistance($origin, direction: 'desc')
    ->get();

foreach ($branches as $branch) {
    $meters = $branch->nearby_distance_meters;
    $distance = $branch->nearbyDistance();

    if ($distance !== null) {
        printf(
            "%s: %.2f km (%.2f mi)\n",
            $branch->name,
            $distance->in(Unit::Kilometer),
            $distance->in(Unit::Mile),
        );
    }
}
```

`orderByDistance()` accepts `asc` or `desc`, case-insensitively, and excludes records with missing or invalid coordinates. It appends distance ordering and an ascending model-key tie-breaker to existing `orderBy()` clauses. Use `reorder()->orderByDistance(...)` when distance should replace earlier ordering. This scope does not select the computed distance; add `withDistance()` when you need it.

`withDistance()` preserves the original models and selected attributes. Called alone, it retains records with null or out-of-range coordinates and returns a null distance for them. Valid distances are unrounded PHP floats in meters, compatible with `Distance::fromMeters()`. `nearbyDistance()` returns the corresponding immutable `Distance` object, or null when no computed result is available. All six distance units remain available.

The read-only `nearby_distance_meters` property is a virtual attribute. Models retrieved through `withDistance()` or `nearestTo()` include it in `toArray()` and JSON, including a null value when selected coordinates are invalid. Ordinary models do not automatically gain this serialized field; direct property access and `nearbyDistance()` still return null. Explicit `$appends`, `$hidden`, and `$visible` settings remain respected.

The trait keeps the value outside Eloquent's persisted attributes, so updating and saving the model, including `saveQuietly()`, does not write it to the database. Assigning it raises `LogicException`. The value describes the query result at the time it was retrieved; changing coordinates, saving, or calling `refresh()` does not recalculate it. Run a new `withDistance()` query when you need an updated result.

Reserve `nearby_distance_meters` for this property. Do not define a real column, default attribute, cast, accessor, or selected SQL alias with that name. Conflicting selections already present when `withDistance()` is called raise `InvalidArgumentException`; conflicting model attributes or casts, and conflicting selected attributes or real columns encountered during result hydration, raise `LogicException`. The package does not inspect database schemas to discover conflicts before querying.

Choose explicit columns before adding the distance:

```php
use App\Models\Branch;
use Zarbinco\Nearby\Coordinate;

$origin = new Coordinate(latitude: 35.6892, longitude: 51.3890);

$branches = Branch::query()
    ->select(['branches.id', 'branches.name'])
    ->withDistance($origin)
    ->get();
```

Calling `select()` afterward replaces the computed selection and raises `LogicException` when affected models are hydrated. Use `addSelect()` to append columns. Repeated `withDistance()` calls expose the result from the last supplied origin; filters and ordering retain the origins passed to their own scopes.

### Pagination and Aggregates

```php
use App\Models\Branch;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;

$origin = new Coordinate(latitude: 35.6892, longitude: 51.3890);

$query = Branch::query()
    ->where('is_active', true)
    ->withinRadius($origin, Distance::fromKilometers(5));

$count = (clone $query)->count();
$hasBranches = (clone $query)->exists();
$closest = (clone $query)->nearestTo($origin, limit: 1)->first();

$page = (clone $query)
    ->withDistance($origin)
    ->orderByDistance($origin)
    ->paginate(20);
```

Use radius or distance-band filtering with ordinary `paginate()`; choose its page size through `paginate()`. `nearestTo()` is intended for a limited result set retrieved with `get()` or `first()`.

The following retrieval methods also preserve geographic filtering and ordering. Computed values are available when the query includes `withDistance()` or `nearestTo()`:

| Retrieval method | Result and distance behavior |
| --- | --- |
| `get()`, `first()`, `firstOrFail()` | Normal Eloquent models or collections with hydrated distances; normal missing-model behavior applies. |
| `paginate()`, `simplePaginate()` | Normal Laravel paginators with computed distances on their models. |
| `cursor()`, `lazy()` | Laravel lazy collections of models with hydrated distances. |
| `chunk()` | Eloquent model batches passed to your callback; an existing nearest-result limit is respected. |
| `pluck()` | A scalar collection of existing database columns. Use `get()->pluck('nearby_distance_meters')` for calculated values. |
| `value()` | The first scalar value, or null for no result; calculated distance works when selected by a proximity scope. |

`cursor()` follows Laravel's usual limitation on eager loading. Use `get()` or `lazy()` when relationships need eager loading. Basic `belongsTo` eager loading and relationship filters compose with the geographic scopes; retain the primary and foreign keys Laravel needs when choosing explicit columns. ID-based chunking with distance ordering has not been verified.

## Query Behavior and Limits

| Method | Filters invalid or missing coordinates | Adds computed distance | Ordering |
| --- | --- | --- | --- |
| `withDistance($origin)` | No; distance is null | Yes | Unchanged |
| `orderByDistance($origin, $direction)` | Yes | No | Appends distance and model key |
| `withinRadius($origin, $radius)` | Yes | No | Unchanged |
| `betweenDistances($origin, $min, $max)` | Yes | No | Unchanged |
| `nearestTo($origin, $limit)` | Yes | Yes | Replaces earlier ordering with distance and model key |

All methods return a normal Eloquent builder and keep ordinary conditions, global scopes, and soft-delete filters. Radius and distance-band conditions execute in MySQL and work with `count()`, `exists()`, `first()`, `get()`, and normal pagination. SQL uses parameter bindings for coordinates and distance thresholds; no values are rounded before filtering.

Basic joins are supported: coordinate columns are qualified with the model table, and the default selection is the model's `table.*`, so joined-table attributes are not selected automatically. Keep the model table as the query's `FROM` source. Model table aliases, subquery `FROM` sources, schema-qualified table names, grouped queries, unions, distinct projections, custom raw result shapes, and cursor pagination are outside the supported query contract. A join that produces duplicate model rows retains normal SQL behavior.

Application global scopes follow Laravel's normal `withoutGlobalScopes()` behavior. Connection validation remains attached to geographic queries and their clones.

The proximity scopes support retrieval and aggregate queries; bulk geographic `update()` and `delete()` operations are outside the supported query contract. Saving an individual retrieved model remains supported.

MySQL 8.4.x is the supported query engine. PostgreSQL, SQLite, MariaDB, and other MySQL versions raise `UnsupportedDatabaseException`; unsupported drivers are rejected before executing the geographic SQL, and server versions are checked when the query is compiled or executed:

```php
use App\Models\Branch;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Exceptions\UnsupportedDatabaseException;

try {
    $branches = Branch::query()
        ->nearestTo(new Coordinate(latitude: 35.6892, longitude: 51.3890))
        ->get();
} catch (UnsupportedDatabaseException $exception) {
    report($exception);
}
```

Every read, write, and failover endpoint must use MySQL 8.4. Retained builders validate replacement PDOs after explicit reconnection and during Laravel's automatic retry of a lost connection. Unsupported replacements raise `UnsupportedDatabaseException` before the geographic statement is prepared. Validation uses PDO's server-version metadata, adds no separate SQL query, and is cached per physical PDO within a query and its clones. Laravel's read/write routing and transaction retry rules remain in effect.

Execute proximity queries through a model using `HasNearbyCoordinates`, Laravel's standard MySQL connection, and its standard base query builder. Custom connection subclasses and custom base query builders are rejected for geographic queries because their execution behavior cannot be validated safely. Basic relationship filters on the geographic model and eager loading remain supported. Embedding geographic scopes in subqueries executed by a different model, or manually executing SQL exported with `toSql()`, is outside the supported query contract.

## Performance

When you search within a radius, Laravel Nearby adds latitude and longitude ranges covering the complete spherical search area. These ranges narrow the candidates before the exact Haversine condition. A rectangle also contains points outside the circle, so the exact distance comparison always remains in the query. `betweenDistances()` uses its maximum distance for the same preliminary filter and retains both exact boundaries.

The ranges handle the antimeridian and poles and expand outward to account for floating-point rounding. The final distance threshold is never expanded. Zero-radius and global searches skip the box, as do numerically uncertain cases where a restrictive box would be unsafe. No candidate limit or implicit radius is added.

Use ordinary application conditions to reduce eligible records and combine a radius with nearest ordering when your use case has a meaningful distance limit:

```php
use App\Models\Branch;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;

$origin = new Coordinate(latitude: 35.6892, longitude: 51.3890);

$branches = Branch::query()
    ->where('is_active', true)
    ->withinRadius($origin, Distance::fromKilometers(5))
    ->nearestTo($origin, limit: 10)
    ->get();
```

Filtering, sorting, and limiting happen in SQL. `nearestTo()` without a radius can still calculate and sort distances for every eligible row: `limit(10)` limits returned rows, not rows examined. A large radius or a dense local cluster can also leave many candidates. Select only the model attributes your application needs when transferring large payloads.

### Optional Indexes

Ordinary B-tree indexes can help MySQL use the preliminary coordinate ranges. Depending on your queries, useful candidates include separate latitude or longitude indexes, `(latitude, longitude)` or `(longitude, latitude)`, or an equality-filtered application column followed by coordinates, such as `(is_active, latitude, longitude)`.

These are optional application decisions, not installation requirements. The package never creates indexes or modifies your schema. For example, an application that frequently filters active branches might evaluate this index in its own development environment:

```sql
CREATE INDEX branches_active_lat_lon
    ON branches (is_active, latitude, longitude);
```

Index order matters. After an index range condition, another indexed column may help filter entries without further narrowing the scanned range. MySQL can prefer a table scan when much of the table matches. Compare plans and timings on representative data before choosing indexes; no layout guarantees the best plan, and ordinary numeric columns are not spatial indexes.

### Inspecting Queries

Use MySQL's `EXPLAIN` with the query's original bindings to inspect the chosen access path:

```php
use App\Models\Branch;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;

$origin = new Coordinate(latitude: 35.6892, longitude: 51.3890);
$query = Branch::query()
    ->where('is_active', true)
    ->withinRadius($origin, Distance::fromKilometers(5))
    ->nearestTo($origin, limit: 10);

$base = $query->toBase();
$plan = $base->getConnection()->select(
    'EXPLAIN FORMAT=JSON '.$base->toSql(),
    $base->getBindings(),
);
```

Inspect the selected key, estimated rows, and any sorting or temporary-table work. `EXPLAIN ANALYZE` executes the query and reports actual iterator activity; use it with care in a development environment. Plans and timings depend on data distribution, selectivity, existing indexes, query shape, and server load. Not every bounded search will be faster.

### Optional Benchmarks

The [benchmark guide](benchmarks/README.md) explains how to compare exact-distance queries with bounded searches on deterministic uniform and clustered datasets, using several ordinary index layouts. From a dedicated MySQL 8.4 development database configured through `DB_*` environment variables:

```bash
php benchmarks/benchmark.php --run --sizes=1000 --warmups=2 --repetitions=5
```

Larger datasets are opt-in. Keep benchmarks separate from production databases and routine CI; performance timings are measurements, not correctness assertions.

## Working with Coordinates

Always supply **latitude first, longitude second**, in decimal degrees:

- Latitude is between -90 and 90, inclusive. Positive values are north of the equator.
- Longitude is between -180 and 180, inclusive. Positive values are east of Greenwich.
- Both parameters accept PHP integers and floats, including zero. Values are stored as floats.
- Longitude -180 is stored as +180: these represent the same antimeridian.

Read coordinates through their public, immutable properties:

```php
use Zarbinco\Nearby\Coordinate;

$location = new Coordinate(latitude: 0, longitude: -180);

echo $location->latitude;  // 0
echo $location->longitude; // 180
```

Numeric strings, booleans, null, arrays, objects, non-finite numbers, and out-of-range coordinates raise `InvalidCoordinateException`. These rules also apply when your calling file does not enable strict typing. Validate and parse HTTP request text in your application before creating a coordinate; the package does not parse request values automatically.

## Calculating Distance

`Geo::distance(Coordinate $origin, Coordinate $destination): Distance` returns the shortest surface distance on the package's spherical Earth model:

```php
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Geo;
use Zarbinco\Nearby\Unit;

$distance = Geo::distance(
    new Coordinate(latitude: 0, longitude: 0),
    new Coordinate(latitude: 0, longitude: 1),
);

echo $distance->in(Unit::Meter);
```

One degree along the equator is approximately 111,195.080 meters on this model. Identical locations return zero, including either pole with different longitudes. Distances across the antimeridian use the shorter arc.

For direct calculator usage, `HaversineCalculator` uses the same implementation as `Geo`:

```php
use Zarbinco\Nearby\Calculators\HaversineCalculator;
use Zarbinco\Nearby\Coordinate;

$calculator = new HaversineCalculator();
$distance = $calculator->calculate(
    new Coordinate(latitude: 0, longitude: 0),
    new Coordinate(latitude: 0, longitude: 1),
);
```

## Supported Units

Conversions use these defined factors, with meters as the internal base unit:

| Enum case | Abbreviation | Meters per unit |
| --- | --- | ---: |
| `Unit::Meter` | `m` | 1 |
| `Unit::Kilometer` | `km` | 1000 |
| `Unit::Mile` | `mi` | 1609.344 |
| `Unit::Foot` | `ft` | 0.3048 |
| `Unit::Yard` | `yd` | 0.9144 |
| `Unit::NauticalMile` | `nmi` | 1852 |

Miles, feet, and yards use international definitions. `Unit` is a string-backed enum; `Unit::Kilometer->value` is `km`, and `Unit::Kilometer->metersPerUnit()` returns `1000.0`.

## Converting Distances

Create a distance with `Distance::from($value, $unit)` or a convenient factory, then use `in($unit)` to get a float in the chosen unit:

```php
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Unit;

$distance = Distance::fromKilometers(5);
$meters = $distance->in(Unit::Meter); // 5000.0
$miles = $distance->in(Unit::Mile);

printf("%.6f mi\n", $miles); // 3.106856 mi

$nauticalDistance = Distance::from(2, Unit::NauticalMile);
$meters = $nauticalDistance->in(Unit::Meter); // 3704.0
```

All factories accept integers and floats:

```php
use Zarbinco\Nearby\Distance;

$meters = Distance::fromMeters(500);
$kilometers = Distance::fromKilometers(5);
$miles = Distance::fromMiles(3);
$feet = Distance::fromFeet(100);
$yards = Distance::fromYards(50);
$nauticalMiles = Distance::fromNauticalMiles(2);
```

Negative distances, invalid input types, NaN, and infinity raise `InvalidDistanceException`. Zero is valid. Conversion also raises this exception if a finite value overflows when stored in meters or returned in another unit. Neither construction nor conversion rounds the result.

## Working with Results

`Distance` is immutable. Convert or round for display without changing the stored value:

```php
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Unit;

$distance = Distance::fromMeters(1234.56789);
$label = number_format($distance->in(Unit::Kilometer), 2).' km';

echo $label; // 1.23 km
$originalMeters = $distance->in(Unit::Meter); // 1234.56789
```

## Handling Invalid Input

Catch the exception matching the value you are constructing. Both exceptions extend PHP's `InvalidArgumentException`:

```php
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Exceptions\InvalidCoordinateException;
use Zarbinco\Nearby\Exceptions\InvalidDistanceException;

try {
    $coordinate = new Coordinate(latitude: 91, longitude: 0);
} catch (InvalidCoordinateException $exception) {
    echo $exception->getMessage();
}

try {
    $distance = Distance::fromMeters(-1);
} catch (InvalidDistanceException $exception) {
    echo $exception->getMessage();
}
```

## Calculation Accuracy

The Haversine formula calculates a great-circle surface distance using a fixed spherical mean Earth radius of **6,371,008.8 meters**. Results are geographic distances, not driving or walking distances, road-network distances, or travel times. The spherical model does not account for terrain, altitude, or Earth's ellipsoidal shape and is not survey-grade ellipsoidal measurement.

Calculations use PHP floating-point arithmetic. Defined conversion factors are used without intentional rounding, but decimal numbers and conversions remain subject to normal binary floating-point limits. Very small values can underflow to zero; nearly antipodal points lose some precision and can round to the half-circumference. Results remain finite and bounded by that half-circumference. Round only when presenting results, according to your application's needs.

MySQL queries use the same radius and Haversine model as the PHP calculator, with clamped intermediate values and no SQL rounding. The two math runtimes can differ slightly. The integration suite uses micrometer-scale absolute tolerances for ordinary examples, smaller tolerances for short arcs, and up to 0.5 meters for difficult oblique antipodal examples. These tolerances describe numerical comparisons, not the accuracy of a spherical model against Earth's real shape.

Stored coordinate precision also matters: a `DECIMAL` column rounds values to its configured scale before calculation. Radius and distance-band boundaries compare MySQL's unrounded result directly with the supplied distance in meters. There is no boundary expansion to compensate for differences between PHP and MySQL.

## Running Tests

From the package directory:

```bash
composer install
composer validate --strict
composer test
composer test:compatibility
composer examples
```

`composer test` runs the standalone suite without connecting to a database. It covers validation (including weakly typed callers), immutability, unit conversions and overflow, and geographic edge cases against analytic references. `composer test:compatibility` exercises Composer constraints and Laravel's Illuminate replacement behavior using isolated metadata fixtures.

For Eloquent integration tests, prepare a dedicated, disposable **MySQL 8.4** database and configure its connection. The tests create, drop, or recreate `nearby_test_branches`, `nearby_test_custom_branches`, and `nearby_test_regions`; never point them at an application or production database.

In Bash, set the connection values for that test database and enter its password when prompted:

```bash
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_DATABASE=nearby_test
export DB_USERNAME=nearby_test
read -s -p 'Test database password: ' DB_PASSWORD
export DB_PASSWORD
composer test:integration
```

Use your shell's environment-variable syntax on other platforms, and keep credentials outside tracked files. `composer test:all` runs both suites with the same database environment. The integration suite executes real SQL through Laravel and Orchestra Testbench; it checks filters, ordering, precision, query composition, model behavior, and input safety. The CI configuration includes a separate MySQL 8.4 service for these tests.

To check actual dependency resolution separately from the quick metadata fixtures:

```bash
composer test:compatibility:real
```

The [consumer testing guide](tools/consumer/README.md) describes installing this checkout into a disposable Laravel application, booting Artisan and HTTP requests, and exercising its existing coordinate columns. Those checks use a separate database ending in `_consumer` and do not install Testbench. The guide also explains security-blocked minimum-version results and why consumer dependencies must be resolved for the PHP runtime being tested.

The executable files in `examples/` demonstrate distance calculation, conversions, direct construction, and exception handling using Composer autoloading alone.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for the development workflow and documentation guidance.

## License

Laravel Nearby is available under the [MIT license](LICENSE).
