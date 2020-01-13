# redlock-php - Redis distributed locks in PHP

[![Total Downloads](https://poser.pugx.org/ginnerpeace/redlock-php/downloads.svg)](https://packagist.org/packages/ginnerpeace/redlock-php)
[![Latest Stable Version](https://poser.pugx.org/ginnerpeace/redlock-php/v/stable.svg)](https://packagist.org/packages/ginnerpeace/redlock-php)
[![Latest Unstable Version](https://poser.pugx.org/ginnerpeace/redlock-php/v/unstable.svg)](https://packagist.org/packages/ginnerpeace/redlock-php)
[![License](https://poser.pugx.org/ginnerpeace/redlock-php/license.svg)](https://packagist.org/packages/ginnerpeace/redlock-php)

Based on [Redlock-php](https://github.com/ronnylt/redlock-php) by [Ronny López](https://github.com/ronnylt)

Based on [Redlock-rb](https://github.com/antirez/redlock-rb) by [Salvatore Sanfilippo](https://github.com/antirez)

This library implements the Redis-based distributed lock manager algorithm [described in this blog post](http://antirez.com/news/77).

### Install

```bash
composer require ginnerpeace/redlock-php
```

### Example

> To create a lock manager

```php

use Ginnerpeace\RedLock\RedLock;

// You can use any redis component to build the instance,
// Redis instance just need to implement the method: eval
$redLock = new RedLock([
    'servers' => [
        [
            // For ext-redis
            function ($host, $port, $timeout) {
                $redis = new \Redis();
                $redis->connect($host, $port, $timeout);
                return $redis;
            },
            ['127.0.0.1', 6379, 0.01]
        ],
        [
            // For Predis
            function ($dsn) {
                return new Predis\Client($dsn);
            },
            ['tcp://10.0.0.1:6379']
        ],
        [
            // For Laravel
            function ($name) {
                return RedisFacade::connection($name)->client();
            },
            ['redis']
        ],
    ],
]);
```

> To acquire a lock

```php

$lock = $redLock->lock('my_resource_name', 1000);

// Or use dynamic retry param.
$retryTime = 10;
$lock = $redLock->lock('my_resource_name', 1000, $retryTime);
```

Where the resource name is an unique identifier of what you are trying to lock
and 1000 is the number of milliseconds for the validity time.

The returned value is `[]` if the lock was not acquired (you may try again),
otherwise an array representing the lock is returned, having three keys:

```php
[
    'validity' => 9897.3020019531
    'resource' => 'my_resource_name',
    'token' => '22f8fd8d0f176ee2e1b7e676ae1f6c8b',
];
```

* validity, an integer representing the number of milliseconds the lock will be valid.
* resource, the name of the locked resource as specified by the user.
* token, a random token value which is used to safe reclaim the lock.

> To release a lock

```php
$redLock->unlock($lock);
```

It is possible to setup the number of retries (by default 3) and the retry
delay (by default 200 milliseconds) used to acquire the lock.

The retry delay is actually chosen at random between `$retryDelay / 2` milliseconds and
the specified `$retryDelay` value.

**Disclaimer**: As stated in the original antirez's version, this code implements an algorithm
which is currently a proposal, it was not formally analyzed. Make sure to understand how it works
before using it in your production environments.
