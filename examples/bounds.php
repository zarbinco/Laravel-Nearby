<?php

declare(strict_types=1);

use Zarbinco\Nearby\Bounds;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Exceptions\InvalidCoordinateException;

require dirname(__DIR__).'/vendor/autoload.php';

$bounds = new Bounds(south: 35.60, west: 51.20, north: 35.80, east: 51.50);
$location = new Coordinate(latitude: 35.6892, longitude: 51.3890);
printf("Location inside viewport: %s\n", $bounds->contains($location) ? 'yes' : 'no');

$wrapped = Bounds::fromViewport(south: -20, west: 175, north: 20, east: 185);
printf("Wrapped viewport: west=%.0f, east=%.0f\n", $wrapped->west, $wrapped->east);
printf("Crosses the antimeridian: %s\n", $wrapped->crossesAntimeridian() ? 'yes' : 'no');

$world = new Bounds(south: -90, west: -180, north: 90, east: 180);
$meridian = new Bounds(south: -90, west: 180, north: 90, east: -180);
$greenwich = new Coordinate(latitude: 0, longitude: 0);
printf("World includes Greenwich: %s\n", $world->contains($greenwich) ? 'yes' : 'no');
printf("Antimeridian-only bounds include Greenwich: %s\n", $meridian->contains($greenwich) ? 'yes' : 'no');

try {
    new Bounds(south: 20, west: 0, north: 10, east: 1);
} catch (InvalidCoordinateException $exception) {
    echo 'Invalid viewport: '.$exception->getMessage()."\n";
}
