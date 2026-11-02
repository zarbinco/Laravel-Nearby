<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

use PDOStatement;

final class BoundsObservedStatement extends PDOStatement
{
    protected function __construct(private readonly BoundsObservedPdo $pdo) {}

    public function execute(?array $params = null): bool
    {
        $this->pdo->observeExecution();

        return parent::execute($params);
    }
}
