<?php

declare(strict_types=1);

namespace Typhoon\OPcache;

use Psr\SimpleCache\CacheException;

/**
 * @internal
 * @psalm-internal Typhoon\OPcache
 */
final class CacheErrorException extends \ErrorException implements CacheException {}
