<?php

declare(strict_types=1);

/**
 * Hashing worker started by BlueDuplicateDetector\Hasher\Threads.
 *
 * hash-worker.php file <input> <output> <chunk> <thread>
 * hash-worker.php redis <host> <port> <session> <chunk> <thread>
 *
 * stdout: one JSON line per processed file {"thread":N,"done":K,"max":M}
 * on failure: message on stderr, exit code 1
 */

use BlueDuplicateDetector\Hasher\FileHash;

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $autoload) {
    if (\is_file($autoload)) {
        require $autoload;
        break;
    }
}

function progress(int $thread, ?int $max = null): \Closure
{
    return static function (string $file, int $done) use ($thread, $max): void {
        echo \json_encode(['thread' => $thread, 'done' => $done] + ($max === null ? [] : ['max' => $max])), "\n";
    };
}

try {
    $mode = $argv[1] ?? '';

    if ($mode === 'file') {
        [, , $input, $output, $chunk, $thread] = $argv;

        $content = \file_get_contents($input);
        $files = $content === false ? false : \unserialize($content, ['allowed_classes' => false]);

        if (!\is_array($files)) {
            throw new \RuntimeException("Invalid file list: $input");
        }

        $result = FileHash::hashList($files, (int)$chunk, progress((int)$thread, \count($files)));
        $data = \serialize(['hashes' => $result->hashes, 'errors' => $result->errors]);

        if (\file_put_contents($output, $data) === false) {
            throw new \RuntimeException("Unable to write result: $output");
        }
    } elseif ($mode === 'redis') {
        [, , $host, $port, $session, $chunk, $thread] = $argv;

        $redis = new \Redis();
        $redis->connect($host, (int)$port, 2.0);

        $key = "$session-paths-$thread";
        $queue = (static function () use ($redis, $key): \Generator {
            while (\is_string($file = $redis->lPop($key))) {
                yield $file;
            }
        })();

        $result = FileHash::hashList($queue, (int)$chunk, progress((int)$thread, (int)$redis->lLen($key)));
        $redis->hSet("$session-hashes", "thread-$thread", \serialize($result->hashes));

        if ($result->errors !== []) {
            $redis->sAdd("$session-errors", ...$result->errors);
        }
    } else {
        throw new \InvalidArgumentException("Unknown mode: $mode");
    }
} catch (\Throwable $exception) {
    \fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
