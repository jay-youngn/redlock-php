<?php declare(strict_types = 1);

namespace Ginnerpeace\RedLock;

class RedLock
{
    private $keyPrefix = 'redLock:';

    private $retryCount = 3;
    private $retryDelay = 200;
    private $clockDriftFactor = 0.01;

    private $quorum;
    private $servers;
    private $instances = [];

    public function __construct(array $config = [])
    {
        $this->parseConfig($config);
        $this->countQuorum();
    }

    /**
     * Trying to get lock.
     *
     * @param string $resource
     * @param int $ttl
     * @param int|null $retry
     * @return array
     *
     * @example
     * Not empty for getted lock.
     * [
     *     'validity' => 100000.0,
     *     'resource' => 'resourceName',
     *     'token' => '5e119d14c928b',
     * ];
     *
     * Empty for lock timeout.
     * [];
     */
    public function lock(string $resource, int $ttl, int $retry = null): array
    {
        $this->initInstances();

        $token = $this->getToken();
        $retry = $retry ?? $this->retryCount;

        do {
            $hitCounts = 0;
            $startTime = microtime(true) * 1000;

            foreach ($this->instances as $instance) {
                if ($this->lockInstance($instance, $resource, $token, $ttl)) {
                    $hitCounts++;
                }
            }

            # Add 2 milliseconds to the drift to account for Redis expires
            # precision, which is 1 millisecond, plus 1 millisecond min drift
            # for small TTLs.
            $drift = ($ttl * $this->clockDriftFactor) + 2;

            $validityTime = $ttl - (microtime(true) * 1000 - $startTime) - $drift;

            if ($hitCounts >= $this->quorum && $validityTime > 0) {
                return [
                    'validity' => $validityTime,
                    'resource' => $resource,
                    'token' => $token,
                ];
            } else {
                foreach ($this->instances as $instance) {
                    $this->unlockInstance($instance, $resource, $token);
                }
            }

            // Wait a random delay before to retry
            $delay = mt_rand(intval(floor($this->retryDelay / 2)), $this->retryDelay);
            usleep($delay * 1000);
        } while ($retry-- > 0);

        return [];
    }

    /**
     * Release the lock.
     *
     * @param array $lock
     * @return void
     */
    public function unlock(array $lock)
    {
        if (! isset($lock['resource'], $lock['token'])) {
            return;
        }

        $this->initInstances();
        $resource = $lock['resource'];
        $token = $lock['token'];

        foreach ($this->instances as $instance) {
            $this->unlockInstance($instance, $resource, $token);
        }
    }

    private function initInstances()
    {
        if (empty($this->instances)) {
            foreach ($this->servers as $server) {
                [$callable, $args] = $server;
                $this->instances[] = call_user_func($callable, ...$args);
            }
        }
    }

    private function lockInstance($instance, string $resource, string $token, int $ttl)
    {
        return $instance->eval('
            return redis.call("SET", KEYS[1], ARGV[1], "NX", ARGV[2], ARGV[3])
        ', [$this->keyPrefix.$resource, null, null, $token, 'PX', $ttl], 3);
    }

    private function unlockInstance($instance, string $resource, string $token)
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            else
                return 0
            end
        ';

        return $instance->eval($script, [
            $this->keyPrefix.$resource,
            $token
        ], 1);
    }

    private function getToken(int $length = 16): string
    {
        return bin2hex(random_bytes($length));
    }

    private function parseConfig(array $config)
    {
        if (isset($config['servers'])) {
            $this->servers = $config['servers'];
        }

        if (isset($config['keyPrefix'])) {
            $this->keyPrefix = $config['keyPrefix'];
        }

        if (isset($config['retryCount'])) {
            $this->retryCount = $config['retryCount'];
        }

        if (isset($config['retryDelay'])) {
            $this->retryDelay = $config['retryDelay'];
        }
    }

    private function countQuorum()
    {
        if (isset($this->servers)) {
            $this->quorum = min(count($this->servers), intval(count($this->servers) / 2 + 1));
        }
    }
}
