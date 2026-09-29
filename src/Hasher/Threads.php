<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

use BlueDuplicateDetector\Progress\Progress;
use React\ChildProcess\Process;
use React\EventLoop\Loop;

/**
 * Always N worker processes, Transport decides how data is exchanged (files or Redis).
 */
final class Threads implements Hasher
{
    public const WORKER = __DIR__ . '/../../bin/hash-worker.php';

    public function __construct(
        private readonly int $threads,
        private readonly Transport $transport,
        private readonly string $php = PHP_BINARY,
    ) {
        if ($threads < 1) {
            throw new \InvalidArgumentException('Number of threads must be greater than 0.');
        }
    }

    public function hash(array $files, int $chunk, Progress $progress): HashResult
    {
        if ($files === []) {
            return new HashResult();
        }

        $progress->start(\count($files), $this->threads);

        try {
            $this->transport->open($files, $this->threads);
            $failed = $this->run($chunk, $progress);

            if ($failed !== []) {
                throw new \RuntimeException('Hash worker failed: ' . \implode('; ', $failed));
            }

            return $this->transport->collect($this->threads);
        } finally {
            $this->transport->close();
            $progress->finish();
        }
    }

    /**
     * @return string[] failure descriptions, empty when all workers succeeded
     */
    private function run(int $chunk, Progress $progress): array
    {
        $loop = Loop::get();
        $buffers = [];
        $stderr = [];
        $failed = [];

        for ($thread = 0; $thread < $this->threads; $thread++) {
            $buffers[$thread] = '';
            $stderr[$thread] = '';
            $args = [$this->php, self::WORKER, ...$this->transport->workerArgs($thread, $chunk)];

            $env = $this->transport->workerEnv();
            $process = new Process(\implode(' ', \array_map('escapeshellarg', $args)), null, $env === [] ? null : $env + \getenv());
            $process->start($loop);

            // stdout chunks may split or join lines, so buffer until "\n"
            $process->stdout->on('data', static function (string $data) use ($thread, &$buffers, $progress): void {
                $buffers[$thread] .= $data;

                while (($end = \strpos($buffers[$thread], "\n")) !== false) {
                    $line = \json_decode(\substr($buffers[$thread], 0, $end), true);
                    $buffers[$thread] = \substr($buffers[$thread], $end + 1);

                    if (isset($line['thread'], $line['done'])) {
                        $progress->advance("thread {$line['thread']}: {$line['done']}");
                        $progress->thread($line['thread'], $line['done'], $line['max'] ?? null);
                    }
                }
            });

            $process->stderr->on('data', static function (string $data) use ($thread, &$stderr): void {
                $stderr[$thread] .= $data;
            });

            $process->on('exit', static function (?int $code) use ($thread, &$stderr, &$failed): void {
                if ($code !== 0) {
                    $message = \trim($stderr[$thread]);
                    $failed[] = "thread $thread exit code " . ($code ?? 'none') . ($message !== '' ? ": $message" : '');
                }
            });
        }

        $loop->run();

        return $failed;
    }
}
