<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Eloquent;

use Closure;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use PDOStatement;
use Zarbinco\Nearby\Exceptions\UnsupportedDatabaseException;

/**
 * Inheritance grants protected access; execution always uses the supplied connection.
 *
 * @internal
 */
final class MySqlQueryExecutor extends MySqlConnection
{
    private function __construct() {}

    /** @param list<MySqlQueryGuard> $guards */
    public static function selectGuarded(
        Connection $connection,
        string $sql,
        array $bindings,
        bool $useReadPdo,
        array $fetchUsing,
        array $guards,
    ): array {
        if ($guards === []) {
            return $connection->select($sql, $bindings, $useReadPdo, $fetchUsing);
        }

        self::assertSupportedConnection($connection);

        return self::runGuarded($connection, $sql, $bindings, static function (string $sql, array $bindings) use (
            $connection, $useReadPdo, $fetchUsing, $guards,
        ): array {
            if ($connection->pretending()) {
                return [];
            }

            return self::prepareAndExecute($connection, $sql, $bindings, $useReadPdo, $guards)
                ->fetchAll(...$fetchUsing);
        });
    }

    /** @param list<MySqlQueryGuard> $guards */
    public static function cursorGuarded(
        Connection $connection,
        string $sql,
        array $bindings,
        bool $useReadPdo,
        array $fetchUsing,
        array $guards,
    ): Generator {
        if ($guards === []) {
            yield from $connection->cursor($sql, $bindings, $useReadPdo, $fetchUsing);

            return;
        }

        self::assertSupportedConnection($connection);

        $statement = self::runGuarded($connection, $sql, $bindings, static function (string $sql, array $bindings) use (
            $connection, $useReadPdo, $guards,
        ): PDOStatement|array {
            if ($connection->pretending()) {
                return [];
            }

            return self::prepareAndExecute($connection, $sql, $bindings, $useReadPdo, $guards);
        });

        if ($statement === []) {
            return;
        }

        while ($record = $statement->fetch(...$fetchUsing)) {
            yield $record;
        }
    }

    /** @param list<MySqlQueryGuard> $guards */
    private static function prepareAndExecute(
        Connection $connection,
        string $sql,
        array $bindings,
        bool $useReadPdo,
        array $guards,
    ): PDOStatement {
        $pdo = $connection->getPdoForSelect($useReadPdo);

        // Laravel invokes this callback again after reconnecting a failed read.
        foreach ($guards as $guard) {
            $guard->validatePdo($connection, $pdo);
        }

        $statement = $connection->prepared($pdo->prepare($sql));
        $connection->bindValues($statement, $connection->prepareBindings($bindings));
        $statement->execute();

        return $statement;
    }

    private static function runGuarded(Connection $connection, string $sql, array $bindings, Closure $callback): mixed
    {
        try {
            return $connection->run($sql, $bindings, $callback);
        } catch (QueryException $exception) {
            if ($exception->getPrevious() instanceof UnsupportedDatabaseException) {
                throw $exception->getPrevious();
            }

            throw $exception;
        }
    }

    private static function assertSupportedConnection(Connection $connection): void
    {
        if (!$connection instanceof MySqlConnection || $connection->getDriverName() !== 'mysql') {
            throw new UnsupportedDatabaseException('Nearby Eloquent queries require a MySQL 8.4 connection.');
        }

        if (get_class($connection) !== MySqlConnection::class) {
            throw new UnsupportedDatabaseException('Nearby geographic queries require Laravel\'s standard MySQL connection; custom execution cannot be validated safely.');
        }
    }
}
