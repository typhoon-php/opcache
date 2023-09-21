<?php

declare(strict_types=1);

namespace Typhoon\OPcache;

use Psr\SimpleCache\InvalidArgumentException;

/**
 * @internal
 * @psalm-internal Typhoon\OPcache
 */
final class InvalidCacheKeyException extends \InvalidArgumentException implements InvalidArgumentException
{
    public function __construct(string $key)
    {
        parent::__construct(sprintf('"%s" is not a valid cache key according to PSR-16 because it contains at least one of the reserved characters: {}()/\@:', $key));
    }
}
