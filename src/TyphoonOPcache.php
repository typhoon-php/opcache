<?php

declare(strict_types=1);

namespace Typhoon\OPcache;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Typhoon\Exporter\Exporter;

/**
 * @psalm-api
 */
final class TyphoonOPcache implements CacheInterface
{
    private static ?bool $opcacheEnabled = null;

    private int $scriptStartTime;

    public function __construct(
        private readonly string $directory,
        private readonly \DateInterval|int|null $defaultTtl = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->scriptStartTime = $_SERVER['REQUEST_TIME'] ?? $this->clock->now()->getTimestamp();
    }

    /**
     * @psalm-suppress MixedArgument
     * @infection-ignore-all
     */
    private static function opcacheEnabled(): bool
    {
        return self::$opcacheEnabled ??= (\function_exists('opcache_invalidate')
            && filter_var(\ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)
            && (!\in_array(\PHP_SAPI, ['cli', 'phpdbg'], true) || filter_var(\ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL)));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->handleErrors(fn (): mixed => $this->doGet($this->clock->now(), $key, $default));
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return $this->handleErrors(function () use ($key, $value, $ttl): bool {
            $expiryDate = $this->calculateExpiryDate($ttl);

            if ($expiryDate === false) {
                $this->doDelete($key);

                return false;
            }

            $this->doSet($key, $value, $expiryDate);

            return true;
        });
    }

    public function delete(string $key): bool
    {
        $this->handleErrors(function () use ($key): void {
            $this->doDelete($key);
        });

        return true;
    }

    public function clear(): bool
    {
        $this->handleErrors(function (): void {
            foreach ($this->iterateDirectory() as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());

                    continue;
                }

                $this->doDeleteFile($file->getPathname());
            }
        });

        return true;
    }

    public function prune(): void
    {
        $this->handleErrors(function (): void {
            $now = $this->clock->now();

            foreach ($this->iterateDirectory() as $file) {
                if ($file->isDir()) {
                    if (scandir($file->getPathname()) === ['.', '..']) {
                        rmdir($file->getPathname());
                    }

                    continue;
                }

                $this->doGetFromFile($now, $file->getPathname());
            }
        });
    }

    /**
     * @param iterable<string> $keys
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): array
    {
        return $this->handleErrors(function () use ($keys, $default): array {
            $now = $this->clock->now();
            $values = [];

            foreach ($keys as $key) {
                $values[$key] = $this->doGet($now, $key, $default);
            }

            return $values;
        });
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        return $this->handleErrors(function () use ($values, $ttl): bool {
            $expiryDate = $this->calculateExpiryDate($ttl);

            if ($expiryDate === false) {
                /** @var string $key */
                foreach ($values as $key => $_value) {
                    $this->doDelete($key);
                }

                return false;
            }

            /** @var string $key */
            foreach ($values as $key => $value) {
                $this->doSet($key, $value, $expiryDate);
            }

            return true;
        });
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $this->handleErrors(function () use ($keys): void {
            foreach ($keys as $key) {
                $this->doDelete($key);
            }
        });

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    private function doGet(\DateTimeImmutable $now, string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        return $this->doGetFromFile($now, $this->file($key), $default);
    }

    private function doGetFromFile(\DateTimeImmutable $now, string $file, mixed $default = null): mixed
    {
        try {
            /** @var array{string, mixed, ?\DateTimeImmutable} */
            $item = include $file;
        } catch (\Throwable $exception) {
            if (!str_contains($exception->getMessage(), 'No such file or directory')) {
                $this->logger->warning('Failed to include cache file {file}.' . $exception->getMessage(), [
                    'exception' => $exception,
                    'file' => $file,
                ]);
            }

            return $default;
        }

        if ($item[2] !== null && $item[2] <= $now) {
            $this->doDeleteFile($file);

            return $default;
        }

        return $item[1];
    }

    private function doSet(string $key, mixed $value, ?\DateTimeImmutable $expiryDate): void
    {
        $this->validateKey($key);
        $file = $this->file($key);
        $directory = \dirname($file);

        if (!is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        /** @infection-ignore-all */
        $tmp = $directory . uniqid(more_entropy: true);
        $handle = fopen($tmp, 'x');
        fwrite($handle, '<?php return' . Exporter::export([$key, $value, $expiryDate]) . ';');
        fclose($handle);

        /**
         * Set mtime in the past to enable OPcache compilation for this file.
         * @infection-ignore-all
         */
        touch($tmp, $this->scriptStartTime - 10);

        rename($tmp, $file);

        if (self::opcacheEnabled()) {
            opcache_invalidate($file, true);
            opcache_compile_file($file);
        }
    }

    private function doDelete(string $key): void
    {
        $this->validateKey($key);
        $this->doDeleteFile($this->file($key));
    }

    private function doDeleteFile(string $file): void
    {
        try {
            unlink($file);
        } catch (\Throwable $exception) {
            if (str_contains($exception->getMessage(), 'No such file or directory')) {
                return;
            }

            /** @psalm-suppress MissingThrowsDocblock */
            throw $exception;
        }

        if (self::opcacheEnabled()) {
            opcache_invalidate($file, true);
        }
    }

    /**
     * @return iterable<\SplFileInfo>
     */
    private function iterateDirectory(): iterable
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        return new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
    }

    /**
     * @return non-empty-string
     */
    private function file(string $key): string
    {
        $hash = hash('xxh128', $key);

        /** @infection-ignore-all */
        return $this->directory . \DIRECTORY_SEPARATOR . $hash[0] . \DIRECTORY_SEPARATOR . $hash[1] . \DIRECTORY_SEPARATOR . substr($hash, 2);
    }

    private function validateKey(string $key): void
    {
        if (preg_match('#[{}()/\\\@:]#', $key)) {
            throw new InvalidCacheKey($key);
        }
    }

    private function calculateExpiryDate(\DateInterval|int|null $ttl): null|false|\DateTimeImmutable
    {
        $ttl ??= $this->defaultTtl;

        if ($ttl === null) {
            return null;
        }

        if (\is_int($ttl)) {
            if ($ttl <= 0) {
                return false;
            }

            /** @var \DateTimeImmutable */
            return $this->clock->now()->modify(sprintf('+%d seconds', $ttl));
        }

        $now = $this->clock->now();
        $expiryDate = $now->add($ttl);

        if ($expiryDate <= $now) {
            return false;
        }

        return $expiryDate;
    }

    /**
     * @template T
     * @param \Closure(): T $function
     * @return T
     */
    private function handleErrors(\Closure $function): mixed
    {
        set_error_handler(static fn (int $level, string $message, string $file, int $line) => throw new CacheErrorException(
            message: $message,
            severity: $level,
            filename: $file,
            line: $line,
        ));

        try {
            return $function();
        } finally {
            restore_error_handler();
        }
    }
}
