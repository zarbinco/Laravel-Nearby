<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

abstract class CustomFactoryBaseModel extends Model
{
    protected function newBaseQueryBuilder(): Builder
    {
        $connection = $this->getConnection();
        $query = new CustomBaseQueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());

        return $query->where($this->qualifyColumn('is_active'), true);
    }
}
