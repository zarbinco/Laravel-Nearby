<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Eloquent;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use PDO;
use WeakMap;
use Zarbinco\Nearby\Exceptions\UnsupportedDatabaseException;

final class MySqlQueryGuard implements Scope
{
    /** @var WeakMap<PDO, true> */
    private WeakMap $validatedConnections;

    private function __construct(private readonly string $table)
    {
        $this->validatedConnections = new WeakMap();
    }

    public static function register(Builder $query): void
    {
        $base = $query->getQuery();

        if (!$base instanceof MySqlQueryBuilder) {
            throw new UnsupportedDatabaseException('Nearby geographic queries require the standard Eloquent base query builder.');
        }

        $guard = null;

        foreach ($base->beforeQueryCallbacks as $callback) {
            if ($callback instanceof self) {
                $guard = $callback;
                break;
            }
        }

        if ($guard === null) {
            $guard = new self($query->getModel()->getTable());
            $base->beforeQuery($guard);
        }

        $base->guardWith($guard);

        // Eloquent applies query-local scopes on a clone. The original callback
        // therefore survives compilation and subsequent executions of this builder.
        $query->withGlobalScope(self::class, $guard);
    }

    public function apply(Builder $builder, Model $model): void
    {
        $base = $builder->getQuery();

        if (!$base instanceof MySqlQueryBuilder) {
            throw new UnsupportedDatabaseException('Nearby geographic queries require the standard Eloquent base query builder.');
        }

        $base->guardWith($this);

        foreach ($base->beforeQueryCallbacks as $callback) {
            if ($callback === $this) {
                return;
            }
        }

        $base->beforeQuery($this);
    }

    public function __invoke(QueryBuilder $query): void
    {
        $this->assertQuerySource($query);
        $connection = $query->getConnection();

        if (!$connection instanceof MySqlConnection || $connection->getDriverName() !== 'mysql') {
            throw new UnsupportedDatabaseException('Nearby Eloquent queries require a MySQL 8.4 connection.');
        }

        // Compilation still validates early. Execution also validates inside
        // Laravel's retried callback, since beforeQuery is not repeated there.
        $connection->reconnectIfMissingConnection();
        $pdo = $query->useWritePdo ? $connection->getPdo() : $connection->getReadPdo();
        $this->validatePdo($connection, $pdo);
    }

    public function assertQuerySource(QueryBuilder $query): void
    {
        if ($query->from !== $this->table) {
            throw new InvalidArgumentException('Nearby queries require the model table as their FROM source; aliases and subqueries are unsupported.');
        }
    }

    public function validatePdo(Connection $connection, PDO $pdo): void
    {
        if (!$connection instanceof MySqlConnection || $connection->getDriverName() !== 'mysql') {
            throw new UnsupportedDatabaseException('Nearby Eloquent queries require a MySQL 8.4 connection.');
        }

        if (get_class($connection) !== MySqlConnection::class) {
            throw new UnsupportedDatabaseException('Nearby geographic queries require Laravel\'s standard MySQL connection; custom execution cannot be validated safely.');
        }

        if (!isset($this->validatedConnections[$pdo])) {
            MySqlDistanceExpression::assertPdoVersion($pdo);
            $this->validatedConnections[$pdo] = true;
        }
    }
}
