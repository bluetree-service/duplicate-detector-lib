<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test\Hasher;

use BlueDuplicateDetector\Hasher\RedisTransport;
use BlueDuplicateDetector\Hasher\SingleProcess;
use BlueDuplicateDetector\Hasher\Threads;
use BlueDuplicateDetector\Progress\NullProgress;
use BlueDuplicateDetector\Scanner;
use BlueDuplicateDetector\Test\Fixture;
use PHPUnit\Framework\TestCase;

class RedisTransportTest extends TestCase
{
    private string $host;

    protected function setUp(): void
    {
        $this->host = \getenv('REDIS_HOST') ?: '127.0.0.1';
    }

    private function requireRedis(): \Redis
    {
        if (!\extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not loaded.');
        }

        try {
            $redis = new \Redis();
            $redis->connect($this->host, 6379, 1.0);

            return $redis;
        } catch (\RedisException) {
            $this->markTestSkipped("Redis not reachable on $this->host:6379.");
        }
    }

    public function testFromDsn(): void
    {
        $this->assertEquals(new RedisTransport(), RedisTransport::fromDsn(null));
        $this->assertEquals(new RedisTransport('redis'), RedisTransport::fromDsn('redis'));
        $this->assertEquals(new RedisTransport('10.0.0.1', 6378), RedisTransport::fromDsn('10.0.0.1:6378'));
    }

    public function testInvalidDsnThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RedisTransport::fromDsn('host:port:x');
    }

    public function testMissingExtensionThrows(): void
    {
        if (\extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis loaded.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ext-redis');

        (new RedisTransport())->open(['/x'], 1);
    }

    public function testUnreachableRedisThrows(): void
    {
        if (!\extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not loaded.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to connect to Redis');

        (new RedisTransport('127.0.0.1', 1))->open(['/x'], 1);
    }

    public function testSameResultAsSingleProcessAndKeysRemoved(): void
    {
        $redis = $this->requireRedis();
        $dir = Fixture::create(Fixture::STANDARD);

        try {
            $files = [...(new Scanner())->scan([$dir]), "$dir/missing"];
            $transport = new RedisTransport($this->host);

            $expected = (new SingleProcess())->hash($files, 0, new NullProgress());
            $actual = (new Threads(3, $transport))->hash($files, 0, new NullProgress());

            $this->assertSame(Fixture::normalize($expected->hashes), Fixture::normalize($actual->hashes));
            $this->assertSame($expected->errors, $actual->errors);

            $session = $transport->session();
            $this->assertSame(0, $redis->exists("$session-paths", "$session-hashes", "$session-errors"));
        } finally {
            Fixture::remove($dir);
        }
    }
}
