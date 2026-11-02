<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Eloquent;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use SplObjectStorage;

/**
 * Keeps connection validation inside the execution callback that Laravel retries.
 *
 * @internal
 */
final class MySqlQueryBuilder extends Builder
{
    private ?MySqlQueryGuard $nearbyGuard = null;

    private ?self $nearbyPaginationSource = null;

    public static function wrap(Builder $query): Builder
    {
        // A custom builder may carry private state or override execution. Leave it
        // intact; adding a geographic scope to it will fail closed in the guard.
        if (get_class($query) !== Builder::class) {
            return $query;
        }

        $wrapped = new self($query->connection, $query->grammar, $query->processor);

        foreach (get_object_vars($query) as $property => $value) {
            $wrapped->{$property} = $value;
        }

        return $wrapped;
    }

    public function guardWith(MySqlQueryGuard $guard): void
    {
        $this->nearbyGuard = $guard;
    }

    protected function runSelect()
    {
        $sql = $this->toSql();

        return MySqlQueryExecutor::selectGuarded(
            $this->connection, $sql, $this->getBindings(), !$this->useWritePdo,
            $this->fetchUsing, $this->guards(),
        );
    }

    protected function runPaginationCountQuery($columns = ['*'])
    {
        if ((!$this->groups && !$this->havings) || $this->guards() === []) {
            return parent::runPaginationCountQuery($columns);
        }

        $clone = $this->cloneForPaginationCount();

        if (is_null($clone->columns) && !empty($this->joins)) {
            $clone->select($this->from.'.*');
        }

        // Laravel compiles the grouped source into a derived table. Retain its
        // guards so the outer count validates the PDO on every execution attempt.
        $count = $this->newQuery();
        $count->nearbyPaginationSource = $clone;

        return $count
            ->from(new Expression('('.$clone->toSql().') as '.$this->grammar->wrap('aggregate_table')))
            ->mergeBindings($clone)
            ->setAggregate('count', $this->withoutSelectAliases($columns))
            ->get()->all();
    }

    public function exists()
    {
        $this->applyBeforeQueryCallbacks();
        $results = MySqlQueryExecutor::selectGuarded(
            $this->connection, $this->grammar->compileExists($this), $this->getBindings(),
            !$this->useWritePdo, [], $this->guards(),
        );

        return isset($results[0]) && (bool) ((array) $results[0])['exists'];
    }

    public function cursor()
    {
        if ($this->columns === null) {
            $this->columns = ['*'];
        }

        return (new LazyCollection(function () {
            $sql = $this->toSql();

            yield from MySqlQueryExecutor::cursorGuarded(
                $this->connection, $sql, $this->getBindings(), !$this->useWritePdo,
                $this->fetchUsing, $this->guards(),
            );
        }))->map(function ($item) {
            return $this->applyAfterQueryCallbacks(new Collection([$item]))->first();
        })->reject(fn ($item) => $item === null);
    }

    public function explain()
    {
        $sql = $this->toSql();

        return new Collection(MySqlQueryExecutor::selectGuarded(
            $this->connection, 'EXPLAIN '.$sql, $this->getBindings(), true, [], $this->guards(),
        ));
    }

    /** @return list<MySqlQueryGuard> */
    private function guards(): array
    {
        $guards = new SplObjectStorage();
        $visited = new SplObjectStorage();
        $this->collectGuards($this, $guards, $visited);

        return iterator_to_array($guards, false);
    }

    private function collectGuards(Builder $query, SplObjectStorage $guards, SplObjectStorage $visited): void
    {
        if (isset($visited[$query])) {
            return;
        }

        $visited[$query] = true;

        if ($query instanceof self && $query->nearbyGuard !== null) {
            $query->nearbyGuard->assertQuerySource($query);
            $guards[$query->nearbyGuard] = true;
        }

        if ($query instanceof self && $query->nearbyPaginationSource !== null) {
            $this->collectGuards($query->nearbyPaginationSource, $guards, $visited);
        }

        // Laravel retains nested WHERE, EXISTS and UNION builders as objects.
        // Validate them against the PDO executing the enclosing statement.
        foreach ([$query->wheres ?? [], $query->unions ?? []] as $clauses) {
            foreach ($clauses as $clause) {
                if (($clause['query'] ?? null) instanceof Builder) {
                    $this->collectGuards($clause['query'], $guards, $visited);
                }
            }
        }
    }
}
