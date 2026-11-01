<?php

declare(strict_types=1);

use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Geo;
use Zarbinco\Nearby\Unit;

require dirname(__DIR__).'/vendor/autoload.php';

$origin = new Coordinate(latitude: 35.6892, longitude: 51.3890);
$destination = new Coordinate(latitude: 35.8400, longitude: 50.9391);
$distance = Geo::distance($origin, $destination);

printf("Geographic distance: %.3f km\n", $distance->in(Unit::Kilometer));
printf("In meters: %.3f m\n", $distance->in(Unit::Meter));
printf("In miles: %.3f mi\n", $distance->in(Unit::Mile));

$radius = Distance::fromKilometers(5);
printf("A 5 km radius is %.6f mi\n", $radius->in(Unit::Mile));

$nauticalDistance = Distance::from(2, Unit::NauticalMile);
printf("2 nautical miles equal %.0f m\n", $nauticalDistance->in(Unit::Meter));
