<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Zarbinco\Nearby\Calculators\HaversineCalculator;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Unit;

final readonly class GeographicBoundingBox
{
    private const float ROUNDING_ALLOWANCE = 64.0 * PHP_FLOAT_EPSILON;

    private const float DEGREE_ALLOWANCE = 360.0 * self::ROUNDING_ALLOWANCE;

    /** @param list<array{float, float}> $longitudeRanges Empty means all longitudes. */
    private function __construct(
        public float $minLatitude,
        public float $maxLatitude,
        public array $longitudeRanges,
    ) {}

    public static function around(Coordinate $origin, Distance $radius): ?self
    {
        $meters = $radius->in(Unit::Meter);
        $earthRadius = HaversineCalculator::MEAN_EARTH_RADIUS_METERS;

        if ($meters === 0.0 || $meters >= M_PI * $earthRadius) {
            return null;
        }

        $angularRadius = $meters / $earthRadius;

        // Budget accumulated double roundoff in the bounded Haversine intermediate.
        // Its inverse automatically allows more angular error near zero and antipodes.
        $a = sin($angularRadius / 2.0) ** 2 + self::ROUNDING_ALLOWANCE;

        if ($a >= 1.0) {
            return null;
        }

        $angularRadius = 2.0 * atan2(sqrt($a), sqrt(1.0 - $a)) + self::ROUNDING_ALLOWANCE;
        $latitudeDelta = rad2deg($angularRadius);
        $minLatitude = max(-90.0, $origin->latitude - $latitudeDelta - self::DEGREE_ALLOWANCE);
        $maxLatitude = min(90.0, $origin->latitude + $latitudeDelta + self::DEGREE_ALLOWANCE);

        if ($minLatitude === -90.0 && $maxLatitude === 90.0) {
            return null;
        }

        if ($minLatitude === -90.0 || $maxLatitude === 90.0) {
            return new self($minLatitude, $maxLatitude, []);
        }

        // At longitude tangency, the spherical sine rule gives sin(delta) / cos(latitude).
        $numerator = sin($angularRadius) + self::ROUNDING_ALLOWANCE;
        $denominator = sin(deg2rad(90.0 - abs($origin->latitude))) - self::ROUNDING_ALLOWANCE;

        if ($numerator >= $denominator) {
            return new self($minLatitude, $maxLatitude, []);
        }

        $longitudeDelta = rad2deg(asin($numerator / $denominator)) + self::DEGREE_ALLOWANCE;

        return new self(
            $minLatitude,
            $maxLatitude,
            self::longitudeRanges($origin->longitude, $longitudeDelta),
        );
    }

    public function applyTo(Builder $query, string $latitudeColumn, string $longitudeColumn): void
    {
        $latitude = MySqlDistanceExpression::qualifiedColumn($query, $latitudeColumn);
        $longitude = MySqlDistanceExpression::qualifiedColumn($query, $longitudeColumn);

        $query->whereRaw($latitude.' BETWEEN ? AND ?', [
            MySqlDistanceExpression::bindNumber($this->minLatitude),
            MySqlDistanceExpression::bindNumber($this->maxLatitude),
        ]);

        if ($this->longitudeRanges === []) {
            return;
        }

        $ranges = $this->longitudeRanges;

        $query->where(static function (Builder $query) use ($longitude, $ranges): void {
            foreach ($ranges as $index => [$minimum, $maximum]) {
                $query->whereRaw($longitude.' BETWEEN ? AND ?', [
                    MySqlDistanceExpression::bindNumber($minimum),
                    MySqlDistanceExpression::bindNumber($maximum),
                ], $index === 0 ? 'and' : 'or');
            }
        });
    }

    /** @return list<array{float, float}> */
    private static function longitudeRanges(float $longitude, float $delta): array
    {
        $minimum = $longitude - $delta;
        $maximum = $longitude + $delta;

        if ($minimum <= -180.0) {
            return [
                [-180.0, $maximum],
                [max(-180.0, $minimum + 360.0 - self::DEGREE_ALLOWANCE), 180.0],
            ];
        }

        if ($maximum >= 180.0) {
            return [
                [$minimum, 180.0],
                [-180.0, min(180.0, $maximum - 360.0 + self::DEGREE_ALLOWANCE)],
            ];
        }

        return [[$minimum, $maximum]];
    }
}
