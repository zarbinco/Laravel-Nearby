<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

use PDO;

final class CountingMySqlPdo extends PDO
{
    public int $serverVersionReads = 0;

    public ?string $reportedVersion = null;

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_SERVER_VERSION) {
            $this->serverVersionReads++;

            if ($this->reportedVersion !== null) {
                return $this->reportedVersion;
            }
        }

        return parent::getAttribute($attribute);
    }
}
