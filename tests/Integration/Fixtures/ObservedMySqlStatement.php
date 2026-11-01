<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

use PDOStatement;

final class ObservedMySqlStatement extends PDOStatement
{
    protected function __construct(private readonly ObservedMySqlPdo $pdo) {}

    public function execute(?array $params = null): bool
    {
        $this->pdo->observeExecution($this->queryString);

        return parent::execute($params);
    }
}
