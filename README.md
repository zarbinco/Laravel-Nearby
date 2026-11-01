# Laravel Nearby

Laravel Nearby calculates the geographic distance between latitude and longitude coordinates and converts distances between common units. Its calculation core is plain PHP: Composer autoloading is enough, with no application bootstrapping, database, or external service required.

## Requirements

- PHP 8.3 or later within the PHP 8 series.
- Laravel 13.0 or later when installed in a Laravel application. Laravel 12 and earlier, including older standalone Illuminate components, are rejected by Composer.

The calculation core has no Laravel runtime dependency. Automated testing covers PHP 8.3, 8.4, and 8.5. Composer compatibility checks verify Laravel 13 dependency resolution; they do not constitute Laravel integration tests. Future Laravel major versions are permitted by the constraints but have not been verified.

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

## Running Tests

From the package directory:

```bash
composer install
composer validate --strict
composer test
composer test:compatibility
composer examples
```

The tests cover validation (including weakly typed callers), immutability, unit conversions and overflow, and geographic edge cases against analytic references. Compatibility checks use Composer's dependency solver with isolated package fixtures and do not install Laravel. GitHub Actions runs the suite on PHP 8.3, 8.4, and 8.5 without database services.

The executable files in `examples/` demonstrate distance calculation, conversions, direct construction, and exception handling using Composer autoloading alone.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for the development workflow and documentation guidance.

## License

Laravel Nearby is available under the [MIT license](LICENSE).
