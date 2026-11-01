<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

use Illuminate\Database\Query\Builder;

final class CustomBuilderBranch extends Branch
{
    protected function newBaseQueryBuilder(): Builder
    {
        $connection = $this->getConnection();

        return new CustomBaseQueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
    }
}
