<?php

declare(strict_types=1);

use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Exceptions\InvalidCoordinateException;
use Zarbinco\Nearby\Exceptions\InvalidDistanceException;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    new Coordinate(latitude: 91, longitude: 0);
} catch (InvalidCoordinateException $exception) {
    echo 'Invalid location: '.$exception->getMessage().PHP_EOL;
}

try {
    Distance::fromMeters(-10);
} catch (InvalidDistanceException $exception) {
    echo 'Invalid distance: '.$exception->getMessage().PHP_EOL;
}

// Validate and parse request text in your application before constructing values.
try {
    new Coordinate(latitude: '35.6892', longitude: 51.3890);
} catch (InvalidCoordinateException $exception) {
    echo 'Text requires application validation: '.$exception->getMessage().PHP_EOL;
}
