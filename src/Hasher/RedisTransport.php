<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

/**
 * Redis queue per thread: files split evenly (last thread gets the same or fewer), each worker pops its own list.
 */
final class RedisTransport implements Transport
{
    private ?\Redis $redis = null;
    private string $session = '';
    private int $threads = 0;

    public const ENV = 'DUPLICATE_DETECTOR_REDIS';

    public const DEFAULTS = ['host' => '127.0.0.1', 'port' => 6379, 'user' => null, 'password' => null, 'database' => 0];

    /**
     * @param array{host: string, port: int, user: ?string, password: ?string, database: int} $config
     */
    private function __construct(private readonly array $config)
    {
    }

    /**
     * @param array{host?: string, port?: int, user?: ?string, password?: ?string, database?: int} $config
     *        missing keys take DEFAULTS
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $config = []): self
    {
        $unknown = \array_diff_key($config, self::DEFAULTS);

        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown Redis option: ' . \implode(', ', \array_keys($unknown)));
        }

        $config += self::DEFAULTS;

        foreach (['host' => 'is_string', 'port' => 'is_int', 'database' => 'is_int'] as $key => $check) {
            if (!$check($config[$key])) {
                throw new \InvalidArgumentException("Redis option $key has invalid type.");
            }
        }

        foreach (['user', 'password'] as $key) {
            if ($config[$key] !== null && !\is_string($config[$key])) {
                throw new \InvalidArgumentException("Redis option $key has invalid type.");
            }
        }

        if ($config['user'] !== null && $config['password'] === null) {
            throw new \InvalidArgumentException('Redis option user requires password.');
        }

        return new self($config);
    }

    /**
     * Connect, authenticate and select database; used by transport and worker.
     *
     * @param array{host: string, port: int, user: ?string, password: ?string, database: int} $config
     * @throws \RuntimeException
     */
    public static function connect(array $config): \Redis
    {
        if (!\extension_loaded('redis')) {
            throw new \RuntimeException('Redis transport requires ext-redis.');
        }

        $redis = new \Redis();

        try {
            if (!$redis->connect($config['host'], $config['port'], 2.0)) {
                throw new \RedisException('connection refused');
            }

            $credentials = $config['user'] !== null ? [$config['user'], $config['password']] : $config['password'];

            if ($credentials !== null && !$redis->auth($credentials)) {
                throw new \RedisException('authentication failed');
            }

            if ($config['database'] !== 0 && !$redis->select($config['database'])) {
                throw new \RedisException("unable to select database {$config['database']}");
            }
        } catch (\RedisException $exception) {
            throw new \RuntimeException(
                "Unable to connect to Redis {$config['host']}:{$config['port']}: {$exception->getMessage()}",
                0,
                $exception
            );
        }

        return $redis;
    }

    public function session(): string
    {
        return $this->session;
    }

    public function open(array $files, int $threads): void
    {
        $redis = self::connect($this->config);

        $this->redis = $redis;
        $this->session = 'dup-' . \bin2hex(\random_bytes(8));

        $this->threads = $threads;

        // own queue per thread (same split as FileTransport), so every thread knows how many files it has
        foreach (\array_chunk($files, \max(1, (int)\ceil(\count($files) / $threads))) as $thread => $share) {
            foreach (\array_chunk($share, 1000) as $part) {
                $this->redis->rPush("$this->session-paths-$thread", ...$part);
            }
        }
    }

    public function workerArgs(int $thread, int $chunk): array
    {
        return ['redis', $this->session, (string)$chunk, (string)$thread];
    }

    /**
     * Connection with credentials goes through environment, arguments are visible to every user in `ps`.
     */
    public function workerEnv(): array
    {
        return [self::ENV => \json_encode($this->config, \JSON_THROW_ON_ERROR)];
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

        $queues = \array_map(fn (int $thread): string => "$this->session-paths-$thread", \range(0, $this->threads - 1));
        $this->redis->del("$this->session-hashes", "$this->session-errors", ...$queues);
        $this->redis->close();
        $this->redis = null;
    }
}
