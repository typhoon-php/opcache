<?php

declare(strict_types=1);

namespace Typhoon\OPcache;

use Psr\SimpleCache\InvalidArgumentException;

/**
 * @internal
 * @psalm-internal Typhoon\OPcache
 */
final class InvalidCacheKey extends \InvalidArgumentException implements InvalidArgumentException
{
    public function __construct(string $key)
    {
        parent::__construct(sprintf('"%s" is not a valid PSR-16 cache key', $key));
    }
}
