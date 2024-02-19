<?php

declare(strict_types=1);

namespace Typhoon\OPcache;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Typhoon\Exporter\Exporter;

/** @psalm-suppress MixedArgument */
\define(
    'TYPHOON_OPCACHE_ENABLED',
    \function_exists('opcache_invalidate')
        && filter_var(\ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)
        && (!\in_array(\PHP_SAPI, ['cli', 'phpdbg'], true) || filter_var(\ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL)),
);

/**
 * @api
 */
final class TyphoonOPcache implements CacheInterface
{
    private int $scriptStartTime;

    public function __construct(
        private readonly string $directory,
        private readonly \DateInterval|int|null $defaultTtl = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->scriptStartTime = $_SERVER['REQUEST_TIME'] ?? $this->clock->now()->getTimestamp();
    }

    private static function isDirectoryEmpty(string $directory): bool
    {
        $handle = opendir($directory);

        while (false !== ($entry = readdir($handle))) {
            if ($entry !== '.' && $entry !== '..') {
                closedir($handle);

                return false;
            }
        }

        closedir($handle);

        return true;
    }

    private static function validateKey(string $key): void
    {
        if (preg_match('#[{}()/\\\@:]#', $key)) {
            throw new InvalidCacheKey($key);
        }
    }

    /**
     * @template T
     * @param \Closure(): T $function
     * @return T
     */
    private static function handleErrors(\Closure $function): mixed
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

    public function get(string $key, mixed $default = null): mixed
    {
        self::validateKey($key);

        return self::handleErrors(fn (): mixed => $this->read($this->file($key), $default, $this->clock->now()));
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        self::validateKey($key);

        return self::handleErrors(function () use ($key, $value, $ttl): bool {
            $expiryDate = $this->calculateExpiryDate($ttl);

            if ($expiryDate === false) {
                $this->unlink($this->file($key));

                return false;
            }

            $this->write($this->file($key), $key, $value, $expiryDate);

            return true;
        });
    }

    public function delete(string $key): bool
    {
        self::validateKey($key);

        self::handleErrors(function () use ($key): void {
            $this->unlink($this->file($key));
        });

        return true;
    }

    public function clear(): bool
    {
        self::handleErrors(function (): void {
            foreach ($this->scanDirectory() as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());

                    continue;
                }

                $this->unlink($file->getPathname());
            }
        });

        return true;
    }

    public function prune(): void
    {
        self::handleErrors(function (): void {
            $now = $this->clock->now();

            foreach ($this->scanDirectory() as $file) {
                if ($file->isDir()) {
                    if (self::isDirectoryEmpty($file->getPathname())) {
                        rmdir($file->getPathname());
                    }

                    continue;
                }

                $this->read($file->getPathname(), null, $now);
            }
        });
    }

    /**
     * @param iterable<string> $keys
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): array
    {
        return self::handleErrors(function () use ($keys, $default): array {
            $now = $this->clock->now();
            $values = [];

            foreach ($keys as $key) {
                self::validateKey($key);

                $values[$key] = $this->read($this->file($key), $default, $now);
            }

            return $values;
        });
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        return self::handleErrors(function () use ($values, $ttl): bool {
            $expiryDate = $this->calculateExpiryDate($ttl);

            if ($expiryDate === false) {
                foreach ($values as $key => $_value) {
                    \assert(\is_string($key));
                    self::validateKey($key);

                    $this->unlink($this->file($key));
                }

                return false;
            }

            foreach ($values as $key => $value) {
                \assert(\is_string($key));
                self::validateKey($key);

                $this->write($this->file($key), $key, $value, $expiryDate);
            }

            return true;
        });
    }

    public function deleteMultiple(iterable $keys): bool
    {
        self::handleErrors(function () use ($keys): void {
            foreach ($keys as $key) {
                self::validateKey($key);

                $this->unlink($this->file($key));
            }
        });

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    private function read(string $file, mixed $default, \DateTimeImmutable $now): mixed
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
            $this->unlink($file);

            return $default;
        }

        return $item[1];
    }

    private function write(string $file, string $key, mixed $value, ?\DateTimeImmutable $expiryDate): void
    {
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

        if (TYPHOON_OPCACHE_ENABLED) {
            opcache_invalidate($file, true);
            opcache_compile_file($file);
        }
    }

    private function unlink(string $file): void
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

        if (TYPHOON_OPCACHE_ENABLED) {
            opcache_invalidate($file, true);
        }
    }

    /**
     * @return iterable<\SplFileInfo>
     */
    private function scanDirectory(): iterable
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
}
