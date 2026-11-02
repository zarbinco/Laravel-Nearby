<?php

namespace Zarbinco\Nearby\Tests\Fixtures;

use Zarbinco\Nearby\Bounds;

// Keep the actual construction and factory call sites weakly typed.
final class WeakBoundsCaller
{
    public static function construct(mixed $south, mixed $west, mixed $north, mixed $east): Bounds
    {
        return new Bounds($south, $west, $north, $east);
    }

    public static function viewport(mixed $south, mixed $west, mixed $north, mixed $east): Bounds
    {
        return Bounds::fromViewport($south, $west, $north, $east);
    }
}
