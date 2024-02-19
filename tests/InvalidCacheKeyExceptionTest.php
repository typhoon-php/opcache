<?php

declare(strict_types=1);

namespace Typhoon\OPcache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvalidCacheKey::class)]
final class InvalidCacheKeyExceptionTest extends TestCase
{
    public function testMessage(): void
    {
        $exception = new InvalidCacheKey('key');

        self::assertSame('"key" is not a valid PSR-16 cache key', $exception->getMessage());
    }
}
