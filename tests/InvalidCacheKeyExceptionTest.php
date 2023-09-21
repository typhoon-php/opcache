<?php

declare(strict_types=1);

namespace Typhoon\OPcache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvalidCacheKeyException::class)]
final class InvalidCacheKeyExceptionTest extends TestCase
{
    public function testMessage(): void
    {
        $exception = new InvalidCacheKeyException('key');

        self::assertSame(
            '"key" is not a valid cache key according to PSR-16 because it contains at least one of the reserved characters: {}()/\@:',
            $exception->getMessage(),
        );
    }
}
