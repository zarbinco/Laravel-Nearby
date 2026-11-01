<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Eloquent;

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

        // Eloquent applies query-local scopes on a clone. The original callback
        // therefore survives compilation and subsequent executions of this builder.
        $query->withGlobalScope(self::class, $guard);
    }

    public function apply(Builder $builder, Model $model): void
    {
        foreach ($builder->getQuery()->beforeQueryCallbacks as $callback) {
            if ($callback === $this) {
                return;
            }
        }

        $builder->getQuery()->beforeQuery($this);
    }

    public function __invoke(QueryBuilder $query): void
    {
        if ($query->from !== $this->table) {
            throw new InvalidArgumentException('Nearby queries require the model table as their FROM source; aliases and subqueries are unsupported.');
        }

        $connection = $query->getConnection();

        if (!$connection instanceof MySqlConnection || $connection->getDriverName() !== 'mysql') {
            throw new UnsupportedDatabaseException('Nearby Eloquent queries require a MySQL 8.4 connection.');
        }

        $pdo = $query->useWritePdo ? $connection->getPdo() : $connection->getReadPdo();

        if (!isset($this->validatedConnections[$pdo])) {
            MySqlDistanceExpression::assertPdoVersion($pdo);
            $this->validatedConnections[$pdo] = true;
        }
    }
}
