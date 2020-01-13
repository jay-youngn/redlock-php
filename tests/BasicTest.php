<?php

use Ginnerpeace\RedLock\RedLock;
use PHPUnit\Framework\TestCase;

class BasicTest extends TestCase
{
    public function testLock()
    {
        $servers = [
            [
                function ($host, $port, $timeout) {
                    $redis = new \Redis();
                    $redis->connect($host, $port, $timeout);
                    return $redis;
                },
                ['127.0.0.1', 6379, 0.02],
            ],
        ];

        $redLock = new RedLock([
            'servers' => $servers,
        ]);

        // Test normal use
        $lock = $redLock->lock('test', 10000);
        $this->assertNotEmpty($lock);

        // Test not acquired
        $lock = $redLock->lock('test', 10000);
        $this->assertEmpty($lock);

        sleep(10);

        // Test unlock
        $lock = $redLock->lock('test', 20000);
        $this->assertNotEmpty($lock);
        $redLock->unlock($lock);
        $lock = $redLock->lock('test', 5000);
        $this->assertNotEmpty($lock);

        // Test retry
        $lock = $redLock->lock('test', 5000, 50);
        $this->assertNotEmpty($lock);
    }
}
