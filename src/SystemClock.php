<?php

declare(strict_types=1);

namespace Typhoon\OPcache;

use Psr\Clock\ClockInterface;

/**
 * @internal
 * @psalm-internal Typhoon\OPcache
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
