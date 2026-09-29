<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test\Hasher;

use BlueDuplicateDetector\Hasher\RedisTransport;
use BlueDuplicateDetector\Hasher\SingleProcess;
use BlueDuplicateDetector\Hasher\Threads;
use BlueDuplicateDetector\Progress\NullProgress;
use BlueDuplicateDetector\Scanner;
use BlueDuplicateDetector\Test\Fixture;
use BlueDuplicateDetector\Test\RecordingProgress;
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

    public function testFromArrayFillsDefaults(): void
    {
        $this->assertEquals(
            RedisTransport::fromArray(['host' => '127.0.0.1', 'port' => 6379, 'user' => null, 'password' => null, 'database' => 0]),
            RedisTransport::fromArray()
        );
    }

    public static function invalidConfig(): array
    {
        return [
            'unknown key' => [['pass' => 'x'], 'Unknown Redis option: pass'],
            'port as string' => [['port' => '6379'], 'port'],
            'user without password' => [['user' => 'dup'], 'password'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidConfig')]
    public function testInvalidConfigThrows(array $config, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        RedisTransport::fromArray($config);
    }

    public function testPasswordGoesToWorkerEnvironmentNotArguments(): void
    {
        $transport = RedisTransport::fromArray(['user' => 'dup', 'password' => 'secret']);

        $this->assertStringNotContainsString('secret', \implode(' ', $transport->workerArgs(0, 0)));
        $this->assertStringContainsString('secret', $transport->workerEnv()[RedisTransport::ENV]);
    }

    public function testMissingExtensionThrows(): void
    {
        if (\extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis loaded.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ext-redis');

        RedisTransport::fromArray()->open(['/x'], 1);
    }

    public function testUnreachableRedisThrows(): void
    {
        if (!\extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not loaded.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to connect to Redis 127.0.0.1:1');

        RedisTransport::fromArray(['port' => 1])->open(['/x'], 1);
    }

    /**
     * Temporary ACL user, removed after the test.
     */
    private function withAclUser(\Redis $redis, \Closure $test): void
    {
        try {
            $redis->rawCommand('ACL', 'SETUSER', 'dup-test', 'on', '>secret', '~*', '+@all');
        } catch (\RedisException $exception) {
            $this->markTestSkipped('Redis ACL not available: ' . $exception->getMessage());
        }

        try {
            $test();
        } finally {
            $redis->rawCommand('ACL', 'DELUSER', 'dup-test');
        }
    }

    public function testAuthenticatesWithUserAndPassword(): void
    {
        $redis = $this->requireRedis();

        $this->withAclUser($redis, function (): void {
            $client = RedisTransport::connect(
                ['host' => $this->host, 'port' => 6379, 'user' => 'dup-test', 'password' => 'secret', 'database' => 0]
            );

            $this->assertSame('dup-test', $client->rawCommand('ACL', 'WHOAMI'));
        });
    }

    public function testWrongPasswordThrows(): void
    {
        $redis = $this->requireRedis();

        $this->withAclUser($redis, function (): void {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("Unable to connect to Redis $this->host:6379");

            RedisTransport::fromArray(['host' => $this->host, 'user' => 'dup-test', 'password' => 'wrong'])->open(['/x'], 1);
        });
    }

    public function testWorkersUseConfiguredDatabase(): void
    {
        $redis = $this->requireRedis();
        $dir = Fixture::create(Fixture::STANDARD);

        try {
            $files = (new Scanner())->scan([$dir]);
            $transport = RedisTransport::fromArray(['host' => $this->host, 'database' => 3]);

            $expected = (new SingleProcess())->hash($files, 0, new NullProgress());
            $actual = (new Threads(2, $transport))->hash($files, 0, new NullProgress());

            $this->assertSame(Fixture::normalize($expected->hashes), Fixture::normalize($actual->hashes));
        } finally {
            Fixture::remove($dir);
        }
    }

    public function testSameResultAsSingleProcessAndKeysRemoved(): void
    {
        $redis = $this->requireRedis();
        $dir = Fixture::create(Fixture::STANDARD);

        try {
            $files = [...(new Scanner())->scan([$dir]), "$dir/missing"];
            $transport = RedisTransport::fromArray(['host' => $this->host]);

            $expected = (new SingleProcess())->hash($files, 0, new NullProgress());
            $actual = (new Threads(3, $transport))->hash($files, 0, new NullProgress());

            $this->assertSame(Fixture::normalize($expected->hashes), Fixture::normalize($actual->hashes));
            $this->assertSame($expected->errors, $actual->errors);

            $session = $transport->session();
            $this->assertSame([], $redis->keys("$session*"));
        } finally {
            Fixture::remove($dir);
        }
    }

    public function testEveryThreadGetsItsOwnShareOfFiles(): void
    {
        $this->requireRedis();
        $dir = Fixture::create(Fixture::STANDARD);

        try {
            $progress = new RecordingProgress();
            (new Threads(3, RedisTransport::fromArray(['host' => $this->host])))->hash((new Scanner())->scan([$dir]), 0, $progress);

            $this->assertSame([[4, 4], [4, 4], [2, 2]], $progress->threads);
        } finally {
            Fixture::remove($dir);
        }
    }
}
