<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

final class UnsafeBranch extends Branch
{
    public static string $latitudeColumn = 'latitude';

    public static string $longitudeColumn = 'longitude';

    protected function nearbyLatitudeColumn(): string
    {
        return self::$latitudeColumn;
    }

    protected function nearbyLongitudeColumn(): string
    {
        return self::$longitudeColumn;
    }
}
