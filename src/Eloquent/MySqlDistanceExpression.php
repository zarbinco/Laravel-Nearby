<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Eloquent;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\MySqlConnection;
use InvalidArgumentException;
use PDO;
use Zarbinco\Nearby\Calculators\HaversineCalculator;
use Zarbinco\Nearby\Coordinate;
use Zarbinco\Nearby\Exceptions\UnsupportedDatabaseException;

final readonly class MySqlDistanceExpression
{
    public string $sql;

    /** @var list<string> */
    public array $bindings;

    public string $validCoordinatesSql;

    public function __construct(
        Builder $query,
        Coordinate $origin,
        string $latitudeColumn,
        string $longitudeColumn,
    ) {
        self::assertSupportedConnection($query);

        $latitudeColumn = self::qualifiedColumn($query, $latitudeColumn);
        $longitudeColumn = self::qualifiedColumn($query, $longitudeColumn);

        $this->validCoordinatesSql = "({$latitudeColumn} IS NOT NULL AND {$longitudeColumn} IS NOT NULL"
            ." AND {$latitudeColumn} BETWEEN -90.0 AND 90.0"
            ." AND {$longitudeColumn} BETWEEN -180.0 AND 180.0)";

        $latitude = "CAST({$latitudeColumn} AS DOUBLE)";
        $longitude = "CAST({$longitudeColumn} AS DOUBLE)";

        // Match the PHP core's short-arc subtraction across the antimeridian.
        $longitudeDifference = "(CASE WHEN ({$longitude} - CAST(? AS DOUBLE)) > 180.0"
            ." THEN ({$longitude} - 180.0) - (CAST(? AS DOUBLE) + 180.0)"
            ." WHEN ({$longitude} - CAST(? AS DOUBLE)) < -180.0"
            ." THEN ({$longitude} + 180.0) - (CAST(? AS DOUBLE) - 180.0)"
            ." ELSE {$longitude} - CAST(? AS DOUBLE) END)";

        $a = "LEAST(1.0, GREATEST(0.0, POWER(SIN(RADIANS({$latitude} - CAST(? AS DOUBLE)) / 2.0), 2)"
            ." + SIN(RADIANS(90.0 - ABS(CAST(? AS DOUBLE)))) * SIN(RADIANS(90.0 - ABS({$latitude})))"
            ." * POWER(SIN(RADIANS({$longitudeDifference}) / 2.0), 2)))";

        $latitudeBinding = self::bindNumber($origin->latitude);
        $longitudeBinding = self::bindNumber($origin->longitude);
        $aBindings = [
            $latitudeBinding,
            $latitudeBinding,
            $longitudeBinding,
            $longitudeBinding,
            $longitudeBinding,
            $longitudeBinding,
            $longitudeBinding,
        ];

        $this->sql = "(CASE WHEN {$this->validCoordinatesSql}"
            ." THEN CAST(? AS DOUBLE) * 2.0 * ATAN2(SQRT({$a}), SQRT(1.0 - {$a})) ELSE NULL END)";
        $this->bindings = [
            self::bindNumber(HaversineCalculator::MEAN_EARTH_RADIUS_METERS),
            ...$aBindings,
            ...$aBindings,
        ];

        MySqlQueryGuard::register($query);
    }

    public static function qualifiedColumn(Builder $query, string $column): string
    {
        self::validateIdentifier($column, 'column');

        $table = $query->getModel()->getTable();
        self::validateIdentifier($table, 'model table');
        self::assertTable($query->getQuery()->from, $table);

        $prefix = $query->getQuery()->getConnection()->getTablePrefix();

        if ($prefix !== '') {
            self::validateIdentifier($prefix, 'table prefix');
        }

        return $query->getQuery()->getGrammar()->wrap($table.'.'.$column);
    }

    public static function bindNumber(float $value): string
    {
        if (!is_finite($value)) {
            throw new InvalidArgumentException('Nearby SQL numerical bindings must be finite.');
        }

        // PDO binds floats as strings; retain the full value independently of locale.
        return str_replace(localeconv()['decimal_point'], '.', sprintf('%.17g', $value));
    }

    public static function assertSupportedConnection(Builder $query): void
    {
        self::assertMySqlConnection($query->getQuery()->getConnection());
    }

    public static function assertServerVersion(Connection $connection, bool $useWritePdo = false): void
    {
        self::assertMySqlConnection($connection);

        $pdo = $useWritePdo ? $connection->getPdo() : $connection->getReadPdo();
        self::assertPdoVersion($pdo);
    }

    public static function assertPdoVersion(PDO $pdo): void
    {
        $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

        if (stripos($version, 'MariaDB') !== false || preg_match('/\A8\.4(?:\.|\z)/', $version) !== 1) {
            throw new UnsupportedDatabaseException(
                'Nearby Eloquent queries require MySQL 8.4; the connected server reports '.$version.'.',
            );
        }
    }

    private static function assertMySqlConnection(Connection $connection): void
    {
        if (!$connection instanceof MySqlConnection || $connection->getDriverName() !== 'mysql') {
            throw new UnsupportedDatabaseException('Nearby Eloquent queries require a MySQL 8.4 connection.');
        }
    }

    private static function validateIdentifier(string $identifier, string $description): void
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $identifier) !== 1) {
            throw new InvalidArgumentException('The nearby '.$description.' must be a simple SQL identifier.');
        }
    }

    private static function assertTable(mixed $from, string $table): void
    {
        if ($from !== $table) {
            throw new InvalidArgumentException(
                'Nearby queries require the model table as their FROM source; aliases and subqueries are unsupported.',
            );
        }
    }
}
