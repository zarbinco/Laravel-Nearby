<?php

declare(strict_types=1);

namespace Zarbinco\Nearby\Tests\Integration\Fixtures;

use Illuminate\Database\Query\Builder;

final class CustomBaseQueryBuilder extends Builder
{
    private string $customState = 'application-builder-state';

    public function customState(): string
    {
        return $this->customState;
    }
}
