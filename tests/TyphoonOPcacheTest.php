<?php

declare(strict_types=1);

namespace Typhoon\OPcache;

use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\Finder\Finder;

#[CoversClass(TyphoonOPcache::class)]
final class TyphoonOPcacheTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = vfsStream::setup()->url();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeys(): array
    {
        return [
            '{' => ['{'],
            '}' => ['}'],
            '(' => ['('],
            ')' => [')'],
            '/' => ['/'],
            '\\' => ['\\'],
            '@' => ['@'],
            ':' => [':'],
        ];
    }

    public function testItUsesSameFileNameRecipe(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);

        $cache->set('key', 'value');

        self::assertFileExists($this->cacheDir . '/a/4/bdf4e564564cf8bbea0d63a05165e3');
    }

    #[DataProvider('invalidKeys')]
    public function testHasThrowsOnInvalidKey(string $invalidKey): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);

        $this->expectExceptionObject(new InvalidCacheKeyException($invalidKey));

        $cache->has($invalidKey);
    }

    public function testCacheSupportsEmptyKey(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $value = 123;
        $cache->set('', $value);

        $has = $cache->has('');
        $cachedValue = $cache->get('');

        self::assertTrue($has);
        self::assertSame($value, $cachedValue);
    }

    public function testHasReturnsFalseIfNoItem(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);

        $has = $cache->has('key');

        self::assertFalse($has);
    }

    public function testHasReturnsFalseIfNoCacheDir(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir . '/no/such/dir');

        $has = $cache->has('key');

        self::assertFalse($has);
    }

    public function testHasReturnsTrueIfItemExists(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('key', 'value');

        $has = $cache->has('key');

        self::assertTrue($has);
    }

    public function testHasReturnsFalseIfItemIsStale(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('key', 'value', 10);
        $cacheInFuture = new TyphoonOPcache($this->cacheDir, clock: $this->createClock('+1 day'));

        $has = $cacheInFuture->has('key');

        self::assertFalse($has);
        $this->assertNumberOfCacheItems(0);
    }

    public function testHasReturnsTrueIfItemHasNullValue(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('key', null);

        $has = $cache->has('key');

        self::assertTrue($has);
    }

    #[DataProvider('invalidKeys')]
    public function testGetThrowsOnInvalidKey(string $invalidKey): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);

        $this->expectExceptionObject(new InvalidCacheKeyException($invalidKey));

        $cache->get($invalidKey);
    }

    public function testGetReturnsDefaultIfNoItem(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);

        $cachedValue = $cache->get('key', $this);

        self::assertSame($this, $cachedValue);
    }

    public function testGetReturnsDefaultIfNoCacheDir(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir . '/no/such/dir');

        $cachedValue = $cache->get('key', $this);

        self::assertSame($this, $cachedValue);
    }

    public function testGetReturnsDefaultIfItemIsStale(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('key', 'value', 10);
        $cacheInFuture = new TyphoonOPcache($this->cacheDir, clock: $this->createClock('+1 day'));

        $cachedValue = $cacheInFuture->get('key', $this);

        self::assertSame($this, $cachedValue);
        $this->assertNumberOfCacheItems(0);
    }

    #[DataProvider('invalidKeys')]
    public function testGetMultipleThrowsOnInvalidKey(string $invalidKey): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);

        $this->expectExceptionObject(new InvalidCacheKeyException($invalidKey));

        $cache->getMultiple(['a', 'b', $invalidKey, 'c']);
    }

    public function testGetMultipleReturnsDefaultIfNotItem(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->setMultiple(['a' => 1]);

        $cachedValues = $cache->getMultiple(['a', 'b'], $this);

        self::assertSame(['a' => 1, 'b' => $this], $cachedValues);
    }

    public function testGetMultipleReturnsDefaultIfItemIsStale(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('a', 1);
        $cache->set('b', 2, 10);
        $cacheInFuture = new TyphoonOPcache($this->cacheDir, clock: $this->createClock('+1 day'));

        $cachedValues = $cacheInFuture->getMultiple(['a', 'b'], $this);

        self::assertSame(['a' => 1, 'b' => $this], $cachedValues);
    }

    public function testGetMultipleReturnsDefaultIfAllItemsAreStale(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('a', 1, 10);
        $cache->set('b', 2, 10);
        $cacheInFuture = new TyphoonOPcache($this->cacheDir, clock: $this->createClock('+1 day'));

        $cachedValues = $cacheInFuture->getMultiple(['a', 'b'], $this);

        self::assertSame(['a' => $this, 'b' => $this], $cachedValues);
        $this->assertNumberOfCacheItems(0);
    }

    #[DataProvider('invalidKeys')]
    public function testSetThrowsOnInvalidKey(string $invalidKey): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);

        $this->expectExceptionObject(new InvalidCacheKeyException($invalidKey));

        $cache->set($invalidKey, 1);
    }

    public function testSetReturnsTrueOnSuccess(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);

        $result = $cache->set('key', 'value');

        self::assertTrue($result);
        $this->assertNumberOfCacheItems(1);
    }

    #[DataProvider('invalidKeys')]
    public function testSetMultipleThrowsOnInvalidKey(string $invalidKey): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);

        $this->expectExceptionObject(new InvalidCacheKeyException($invalidKey));

        $cache->setMultiple(['a' => 1, $invalidKey => 2, 'b' => 3]);
    }

    public function testSetMultipleReturnsTrueOnSuccess(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);

        $result = $cache->setMultiple(['a' => 1, 'c' => 2, 'b' => 3]);

        self::assertTrue($result);
        $this->assertNumberOfCacheItems(3);
    }

    public function testItemIsAliveJustBeforeEndOfIntTtl(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir, clock: $this->createClock('00:00:00'));
        $cache->set('key', 'value', ttl: 1);
        $cacheInFuture = new TyphoonOPcache($this->cacheDir, clock: $this->createClock('00:00:00.999999'));

        $alive = $cacheInFuture->has('key');

        self::assertTrue($alive);
    }

    public function testItemIsDeadRightWhenIntTtlEnds(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir, clock: $this->createClock('00:00:00'));
        $cache->set('key', 'value', ttl: 1);
        $cacheInFuture = new TyphoonOPcache($this->cacheDir, clock: $this->createClock('00:00:01'));

        $alive = $cacheInFuture->has('key');

        self::assertFalse($alive);
    }

    public function testItemIsAliveJustBeforeEndOfIntervalTtl(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir, clock: $this->createClock('00:00:00'));
        $cache->set('key', 'value', new \DateInterval('PT1S'));
        $cacheInFuture = new TyphoonOPcache($this->cacheDir, clock: $this->createClock('00:00:00.999999'));

        $alive = $cacheInFuture->has('key');

        self::assertTrue($alive);
    }

    public function testItemIsDeadRightWhenIntervalTtlEnds(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir, clock: $this->createClock('00:00:00'));
        $cache->set('key', 'value', new \DateInterval('PT1S'));
        $cacheInFuture = new TyphoonOPcache($this->cacheDir, clock: $this->createClock('00:00:01'));

        $alive = $cacheInFuture->has('key');

        self::assertFalse($alive);
    }

    public function testItemIsDeletedOnSetIfIntTtlIsZero(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('key', 'value', ttl: 100);

        $setResult = $cache->set('key', 'new_value', ttl: 0);

        self::assertFalse($setResult);
        self::assertFalse($cache->has('key'));
        $this->assertNumberOfCacheItems(0);
    }

    public function testItemIsDeletedOnSetIfIntervalTtlIsZero(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('key', 'value', ttl: 100);

        $setResult = $cache->set('key', 'new_value', new \DateInterval('P0Y'));

        self::assertFalse($setResult);
        self::assertFalse($cache->has('key'));
        $this->assertNumberOfCacheItems(0);
    }

    public function testItemIsDeletedOnSetIfIntTtlIsNegative(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('key', 'value', ttl: 100);

        $setResult = $cache->set('key', 'new_value', ttl: -1);

        self::assertFalse($setResult);
        self::assertFalse($cache->has('key'));
        $this->assertNumberOfCacheItems(0);
    }

    public function testItemIsDeletedOnSetIfIntervalTtlIsNegative(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('key', 'value', ttl: 100);
        $interval = new \DateInterval('P1D');
        $interval->invert = 1;

        $setResult = $cache->set('key', 'new_value', $interval);

        self::assertFalse($setResult);
        self::assertFalse($cache->has('key'));
        $this->assertNumberOfCacheItems(0);
    }

    public function testItemIsDeletedOnSetMultipleIfIntTtlIsZero(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->setMultiple(['key' => 'value', 'key2' => 'value2'], ttl: 100);

        $setResult = $cache->setMultiple(['key' => 'value', 'key2' => 'value2'], ttl: 0);

        self::assertFalse($setResult);
        self::assertFalse($cache->has('key'));
        self::assertFalse($cache->has('key2'));
        $this->assertNumberOfCacheItems(0);
    }

    public function testItemIsDeletedOnSetMultipleIfIntervalTtlIsZero(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('key', 'value', ttl: 100);

        $setResult = $cache->setMultiple(['key' => 'value', 'key2' => 'value2'], new \DateInterval('P0Y'));

        self::assertFalse($setResult);
        self::assertFalse($cache->has('key'));
        self::assertFalse($cache->has('key2'));
        $this->assertNumberOfCacheItems(0);
    }

    public function testItemIsDeletedOnSetMultipleIfIntTtlIsNegative(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->setMultiple(['key' => 'value', 'key2' => 'value2'], ttl: 100);

        $setResult = $cache->setMultiple(['key' => 'value', 'key2' => 'value2'], ttl: -1);

        self::assertFalse($setResult);
        self::assertFalse($cache->has('key'));
        self::assertFalse($cache->has('key2'));
        $this->assertNumberOfCacheItems(0);
    }

    public function testItemIsDeletedOnSetMultipleIfIntervalTtlIsNegative(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('key', 'value', ttl: 100);
        $interval = new \DateInterval('P1D');
        $interval->invert = 1;

        $setResult = $cache->setMultiple(['key' => 'value', 'key2' => 'value2'], $interval);

        self::assertFalse($setResult);
        self::assertFalse($cache->has('key'));
        self::assertFalse($cache->has('key2'));
        $this->assertNumberOfCacheItems(0);
    }

    #[DataProvider('invalidKeys')]
    public function testDeleteThrowsOnInvalidKey(string $invalidKey): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);

        $this->expectExceptionObject(new InvalidCacheKeyException($invalidKey));

        $cache->delete($invalidKey);
    }

    public function testDeleteWorks(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('key', 'value');
        $cache->set('key2', 'value2');

        $result = $cache->delete('key');

        self::assertTrue($result);
        $this->assertNumberOfCacheItems(1);
        self::assertFalse($cache->has('key'));
        self::assertTrue($cache->has('key2'));
    }

    #[DataProvider('invalidKeys')]
    public function testDeleteMultipleThrowsOnInvalidKey(string $invalidKey): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);

        $this->expectExceptionObject(new InvalidCacheKeyException($invalidKey));

        $cache->deleteMultiple(['a', $invalidKey, 'c']);
    }

    public function testDeleteMultipleWorks(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('key', 'value');
        $cache->set('key2', 'value2');
        $cache->set('key3', 'value3');

        $result = $cache->deleteMultiple(['key', 'key3']);

        self::assertTrue($result);
        $this->assertNumberOfCacheItems(1);
        self::assertFalse($cache->has('key'));
        self::assertTrue($cache->has('key2'));
        self::assertFalse($cache->has('key3'));
    }

    public function testClearWorks(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('key', 'value');
        $cache->set('key2', 'value2');
        $cache->set('key3', 'value3');

        $cache->clear();

        $this->assertCacheDirIsEmpty();
        $this->assertNumberOfCacheItems(0);
    }

    public function testClearDoesNotFailIfNoDir(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir . '/no/such/dir');

        $result = $cache->clear();

        self::assertTrue($result);
    }

    public function testPruneWorks(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('key', 'value', ttl: 1);
        $cache->set('key2', 'value2');
        $cache->set('key3', 'value3', ttl: 1);
        $cacheInFuture = new TyphoonOPcache($this->cacheDir, clock: $this->createClock('+2 seconds'));

        $cacheInFuture->prune();

        $this->assertNumberOfCacheItems(1);
        self::assertFalse($cache->has('key'));
        self::assertTrue($cache->has('key2'));
        self::assertFalse($cache->has('key3'));
    }

    public function testPruneDoesNotFailIfNoDir(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir . '/no/such/dir');

        $cache->prune();

        self::expectNotToPerformAssertions();
    }

    public function testPruneRemovesEmptyDirectories(): void
    {
        $cache = new TyphoonOPcache($this->cacheDir);
        $cache->set('a', 'a', 1);
        $cacheInFuture = new TyphoonOPcache($this->cacheDir, clock: $this->createClock('+2 seconds'));

        $cacheInFuture->prune();

        $this->assertCacheDirIsEmpty();
    }

    public function testItLogsUnserializationProblemsWithoutFailing(): void
    {
        $logger = new class () extends AbstractLogger {
            /**
             * @var list<array{mixed, \Stringable|string, array}>
             */
            public array $logs = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = [$level, $message, $context];
            }
        };
        $cache = new TyphoonOPcache($this->cacheDir, logger: $logger);
        $cache->set('key', new NonUnserializable());

        $value = $cache->get('key');

        self::assertNull($value);
        self::assertCount(1, $logger->logs);
        self::assertSame(LogLevel::WARNING, $logger->logs[0][0]);
        self::assertSame('Failed to include cache file {file}.I cannot unserialize!', $logger->logs[0][1]);
        self::assertIsString($logger->logs[0][2]['file'] ?? null);
        self::assertEquals(
            new CacheErrorException(message: 'I cannot unserialize!', severity: E_USER_ERROR),
            $logger->logs[0][2]['exception'] ?? null,
        );
    }

    public function testItRestoresErrorHandler(): void
    {
        $currentHandler = $this->getCurrentErrorHandler();
        $cache = new TyphoonOPcache($this->cacheDir);

        $cache->has('key');

        self::assertSame($currentHandler, $this->getCurrentErrorHandler());
    }

    private function createClock(string $time): ClockInterface
    {
        return new class (new \DateTimeImmutable($time)) implements ClockInterface {
            public function __construct(
                private readonly \DateTimeImmutable $time,
            ) {}

            public function now(): \DateTimeImmutable
            {
                return $this->time;
            }
        };
    }

    private function assertNumberOfCacheItems(int $number): void
    {
        self::assertCount($number, (new Finder())->files()->in($this->cacheDir));
    }

    private function assertCacheDirIsEmpty(): void
    {
        self::assertSame(['.', '..'], scandir($this->cacheDir));
    }

    private function getCurrentErrorHandler(): ?callable
    {
        $currentHandler = set_error_handler(static fn (): bool => true);
        restore_error_handler();

        return $currentHandler;
    }
}
