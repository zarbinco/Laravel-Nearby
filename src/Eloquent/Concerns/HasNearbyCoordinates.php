<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Eloquent\Concerns;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use LogicException;
use Zarbinco\Nearby\Bounds;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Distance;
use Zarbinco\Nearby\Eloquent\GeographicBoundingBox;
use Zarbinco\Nearby\Eloquent\MySqlDistanceExpression;
use Zarbinco\Nearby\Eloquent\MySqlQueryBuilder;
use Zarbinco\Nearby\Eloquent\MySqlQueryGuard;
use Zarbinco\Nearby\Exceptions\InvalidDistanceException;
use Zarbinco\Nearby\Unit;

trait HasNearbyCoordinates
{
    private ?float $nearbyComputedDistanceMeters = null;

    protected function newBaseQueryBuilder(): QueryBuilder
    {
        return MySqlQueryBuilder::wrap(parent::newBaseQueryBuilder());
    }

    public function initializeHasNearbyCoordinates(): void
    {
        // Keep this hook available for models that explicitly call the initializer.
    }

    protected function nearbyLatitudeColumn(): string
    {
        return 'latitude';
    }

    protected function nearbyLongitudeColumn(): string
    {
        return 'longitude';
    }

    protected function nearbyDistanceMeters(): Attribute
    {
        return Attribute::make(
            get: function (): ?float {
                if (array_key_exists('nearby_distance_meters', $this->attributes)) {
                    throw new LogicException('nearby_distance_meters is reserved for the calculated distance; rename the conflicting attribute.');
                }

                return $this->nearbyComputedDistanceMeters;
            },
            set: static fn () => throw new LogicException('nearby_distance_meters is a read-only calculated attribute.'),
        );
    }

    public function nearbyDistance(): ?Distance
    {
        $meters = $this->nearby_distance_meters;

        return $meters === null ? null : Distance::fromMeters($meters);
    }

    #[Scope]
    protected function withDistance(Builder $query, Coordinate $origin): Builder
    {
        $expression = $this->nearbyExpression($query, $origin);
        $this->nearbyAssertAvailableAttribute($query);

        // A separate SQL alias lets hydration detect a real reserved column safely.
        $alias = '__nearby_'.bin2hex(random_bytes(12));
        $query->selectRaw($expression->sql.' AS '.$query->getQuery()->getGrammar()->wrap($alias), $expression->bindings);
        $query->afterQuery(static function (mixed $models) use ($alias): void {
            if (!$models instanceof Collection) {
                return;
            }

            foreach ($models as $model) {
                $model->nearbyHydrateDistance($alias);
            }
        });

        return $query;
    }

    #[Scope]
    protected function orderByDistance(Builder $query, Coordinate $origin, mixed $direction = 'asc'): Builder
    {
        if (!is_string($direction) || !in_array(strtolower($direction), ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Distance order direction must be asc or desc.');
        }

        $expression = $this->nearbyExpression($query, $origin);

        return $query->whereRaw($expression->validCoordinatesSql)
            ->orderByRaw($expression->sql.' '.strtolower($direction), $expression->bindings)
            ->orderByRaw(MySqlDistanceExpression::qualifiedColumn($query, $this->getKeyName()).' ASC');
    }

    #[Scope]
    protected function withinBounds(Builder $query, Bounds $bounds): Builder
    {
        MySqlDistanceExpression::assertSupportedConnection($query);
        $latitude = MySqlDistanceExpression::qualifiedColumn($query, $this->nearbyLatitudeColumn());
        $longitude = MySqlDistanceExpression::qualifiedColumn($query, $this->nearbyLongitudeColumn());
        MySqlQueryGuard::register($query);

        if ($query->getQuery()->columns === null) {
            $query->select($this->getTable().'.*');
        }

        $ranges = $bounds->crossesAntimeridian()
            ? [[$bounds->west, 180.0], [-180.0, $bounds->east]]
            : [[$bounds->west, $bounds->east]];

        // Both signed representations describe the same inclusive meridian.
        if (!$bounds->crossesAntimeridian() && $bounds->west === -180.0 && $bounds->east < 180.0) {
            $ranges[] = [180.0, 180.0];
        } elseif (!$bounds->crossesAntimeridian() && $bounds->east === 180.0 && $bounds->west > -180.0) {
            $ranges[] = [-180.0, -180.0];
        }

        $bindings = [
            MySqlDistanceExpression::bindNumber($bounds->south),
            MySqlDistanceExpression::bindNumber($bounds->north),
        ];
        $longitudeConditions = [];

        foreach ($ranges as [$minimum, $maximum]) {
            $longitudeConditions[] = $longitude.' BETWEEN CAST(? AS DOUBLE) AND CAST(? AS DOUBLE)';
            $bindings[] = MySqlDistanceExpression::bindNumber($minimum);
            $bindings[] = MySqlDistanceExpression::bindNumber($maximum);
        }

        return $query->whereRaw(
            // Native domain comparisons also reject high-scale DECIMAL values
            // just outside the valid range that would round onto a DOUBLE edge.
            '('.$latitude.' BETWEEN -90.0 AND 90.0 AND '.$longitude.' BETWEEN -180.0 AND 180.0'
                .' AND '.$latitude.' BETWEEN CAST(? AS DOUBLE) AND CAST(? AS DOUBLE) AND ('
                .implode(' OR ', $longitudeConditions).'))',
            $bindings,
        );
    }

    #[Scope]
    protected function withinRadius(Builder $query, Coordinate $origin, Distance $radius): Builder
    {
        $expression = $this->nearbyExpression($query, $origin);
        GeographicBoundingBox::around($origin, $radius)?->applyTo($query, $this->nearbyLatitudeColumn(), $this->nearbyLongitudeColumn());

        return $query->whereRaw(
            $expression->sql.' <= CAST(? AS DOUBLE)',
            [...$expression->bindings, MySqlDistanceExpression::bindNumber($radius->in(Unit::Meter))],
        );
    }

    #[Scope]
    protected function betweenDistances(Builder $query, Coordinate $origin, Distance $min, Distance $max): Builder
    {
        $minimum = $min->in(Unit::Meter);
        $maximum = $max->in(Unit::Meter);

        if ($minimum > $maximum) {
            throw new InvalidDistanceException('Minimum distance must not be greater than maximum distance.');
        }

        $expression = $this->nearbyExpression($query, $origin);
        GeographicBoundingBox::around($origin, $max)?->applyTo($query, $this->nearbyLatitudeColumn(), $this->nearbyLongitudeColumn());

        return $query->whereRaw(
            $expression->sql.' BETWEEN CAST(? AS DOUBLE) AND CAST(? AS DOUBLE)',
            [...$expression->bindings, MySqlDistanceExpression::bindNumber($minimum), MySqlDistanceExpression::bindNumber($maximum)],
        );
    }

    #[Scope]
    protected function nearestTo(Builder $query, Coordinate $origin, mixed $limit = 10): Builder
    {
        if (!is_int($limit) || $limit < 1) {
            throw new InvalidArgumentException('Nearest result limit must be a positive integer.');
        }

        return $query->withDistance($origin)->reorder()->orderByDistance($origin)->limit($limit);
    }

    private function nearbyExpression(Builder $query, Coordinate $origin): MySqlDistanceExpression
    {
        $expression = new MySqlDistanceExpression($query, $origin, $this->nearbyLatitudeColumn(), $this->nearbyLongitudeColumn());

        if ($query->getQuery()->columns === null) {
            $query->select($this->getTable().'.*');
        }

        return $expression;
    }

    private function nearbyAssertAvailableAttribute(Builder $query): void
    {
        if (array_key_exists('nearby_distance_meters', $this->getAttributes())
            || array_key_exists('nearby_distance_meters', $this->getCasts())) {
            throw new LogicException('nearby_distance_meters is reserved for the calculated distance; remove the conflicting attribute or cast.');
        }

        foreach ($query->getQuery()->columns ?? [] as $column) {
            $sql = $column instanceof Expression ? $column->getValue($query->getQuery()->getGrammar()) : $column;

            if (is_string($sql) && preg_match('/\bnearby_distance_meters\b/i', $sql)) {
                throw new InvalidArgumentException('nearby_distance_meters is reserved and cannot appear in an explicit selection.');
            }
        }
    }

    private function nearbyHydrateDistance(string $alias): void
    {
        if (array_key_exists('nearby_distance_meters', $this->attributes)) {
            throw new LogicException('nearby_distance_meters is reserved for the calculated distance; rename the conflicting selected attribute or database column.');
        }

        if (!array_key_exists($alias, $this->attributes)) {
            throw new LogicException('The calculated distance selection was removed. Call select() before withDistance() or nearestTo(), or use addSelect().');
        }

        $value = $this->attributes[$alias];
        $this->nearbyComputedDistanceMeters = $value === null ? null : (float) $value;
        $this->append('nearby_distance_meters');
        unset($this->attributes[$alias], $this->original[$alias]);
    }
}
