<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

use Illuminate\Database\MySqlConnection;

final class CustomMySqlConnection extends MySqlConnection
{
    public int $selectCalls = 0;

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->selectCalls++;

        return parent::select($query, $bindings, $useReadPdo, $fetchUsing);
    }
}
