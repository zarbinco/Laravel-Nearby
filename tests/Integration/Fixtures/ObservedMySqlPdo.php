<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

use PDO;
use PDOStatement;

final class ObservedMySqlPdo extends PDO
{
    public int $serverVersionReads = 0;

    public int $geographicPrepareAttempts = 0;

    public int $geographicExecuteAttempts = 0;

    public ?string $reportedVersion = null;

    /** @var list<string> */
    public array $events = [];

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_SERVER_VERSION) {
            $this->serverVersionReads++;
            $this->events[] = 'version';

            if ($this->reportedVersion !== null) {
                return $this->reportedVersion;
            }
        }

        return parent::getAttribute($attribute);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (self::isGeographic($query)) {
            $this->geographicPrepareAttempts++;
            $this->events[] = 'prepare';
        }

        return parent::prepare($query, $options);
    }

    public function exec(string $statement): int|false
    {
        $this->observeExecution($statement);

        return parent::exec($statement);
    }

    public function observeExecution(string $query): void
    {
        if (self::isGeographic($query)) {
            $this->geographicExecuteAttempts++;
            $this->events[] = 'execute';
        }
    }

    private static function isGeographic(string $query): bool
    {
        return stripos($query, 'ATAN2(') !== false;
    }
}
