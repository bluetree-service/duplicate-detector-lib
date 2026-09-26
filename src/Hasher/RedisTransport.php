<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

/**
 * Shared Redis queue: workers pop paths until the list is empty, so fast threads take more files.
 */
final class RedisTransport implements Transport
{
    private ?\Redis $redis = null;
    private string $session = '';

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
    ) {
    }

    /**
     * @param string|null $dsn host[:port], null or empty = defaults
     * @throws \InvalidArgumentException
     */
    public static function fromDsn(?string $dsn): self
    {
        if ($dsn === null || $dsn === '') {
            return new self();
        }

        if (!\preg_match('/^([^:]+)(?::(\d+))?$/', $dsn, $match)) {
            throw new \InvalidArgumentException("Invalid Redis address: $dsn (expected host[:port])");
        }

        return new self($match[1], isset($match[2]) ? (int)$match[2] : 6379);
    }

    public function session(): string
    {
        return $this->session;
    }

    public function open(array $files, int $threads): void
    {
        if (!\extension_loaded('redis')) {
            throw new \RuntimeException('Redis transport requires ext-redis.');
        }

        $redis = new \Redis();

        try {
            if (!$redis->connect($this->host, $this->port, 2.0)) {
                throw new \RedisException('connection refused');
            }
        } catch (\RedisException $exception) {
            throw new \RuntimeException(
                "Unable to connect to Redis $this->host:$this->port: {$exception->getMessage()}",
                0,
                $exception
            );
        }

        $this->redis = $redis;
        $this->session = 'dup-' . \bin2hex(\random_bytes(8));

        foreach (\array_chunk($files, 1000) as $part) {
            $this->redis->rPush("$this->session-paths", ...$part);
        }
    }

    public function workerArgs(int $thread, int $chunk): array
    {
        return ['redis', $this->host, (string)$this->port, $this->session, (string)$chunk, (string)$thread];
    }

    public function collect(int $threads): HashResult
    {
        $result = new HashResult([], $this->redis->sMembers("$this->session-errors") ?: []);

        for ($thread = 0; $thread < $threads; $thread++) {
            $data = $this->redis->hGet("$this->session-hashes", "thread-$thread");
            $hashes = \is_string($data) ? \unserialize($data, ['allowed_classes' => false]) : false;

            if (!\is_array($hashes)) {
                throw new \RuntimeException("Missing result of thread $thread");
            }

            $result = $result->merge(new HashResult($hashes));
        }

        return $result;
    }

    public function close(): void
    {
        if ($this->redis === null) {
            return;
        }

        $this->redis->del("$this->session-paths", "$this->session-hashes", "$this->session-errors");
        $this->redis->close();
        $this->redis = null;
    }
}
