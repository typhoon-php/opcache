# Typhoon OPcache

[PSR-16](https://www.php-fig.org/psr/psr-16) compliant cache that stores values as PHP files, suitable for OPcaching.

## Installation

`composer require typhoon/opcache`

## Usage

```php
use Typhoon\OPcache\TyphoonOPcache;

$cache = new TyphoonOPcache('path/to/cache/dir');

$cache->set('key', $value);

assert($cache->get('key') == $value);
```

## How to configure default TTL

According to [PSR-16](https://www.php-fig.org/psr/psr-16/#12-definitions):

> If a calling library asks for an item to be saved but does not specify an expiration time, 
> or specifies a null expiration time or TTL, an Implementing Library MAY use a configured default duration.

Here's how you can configure default TTL:

```php
use Typhoon\OPcache\TyphoonOPcache;

$cache = new TyphoonOPcache(
    directory: 'path/to/cache/dir',
    defaultTtl: new DateInterval('T1M'),
);
```

## How to delete stale cache items

```php
use Typhoon\OPcache\TyphoonOPcache;

$cache = new TyphoonOPcache('path/to/cache/dir');
$cache->prune();
```
