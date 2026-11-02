<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

use PDO;
use PDOStatement;

final class BoundsObservedPdo extends PDO
{
    public int $serverVersionReads = 0;

    public int $prepareAttempts = 0;

    public int $executeAttempts = 0;

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
        $this->prepareAttempts++;
        $this->events[] = 'prepare';

        return parent::prepare($query, $options);
    }

    public function exec(string $statement): int|false
    {
        $this->observeExecution();

        return parent::exec($statement);
    }

    public function observeExecution(): void
    {
        $this->executeAttempts++;
        $this->events[] = 'execute';
    }
}
