<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

/**
 * File list split into chunks, one serialized input and output file per worker.
 * serialize() instead of JSON, file names don't have to be valid UTF-8.
 */
final class FileTransport implements Transport
{
    private ?string $dir = null;

    public function __construct(private readonly string $tmpDir = '')
    {
    }

    public function open(array $files, int $threads): void
    {
        $this->dir = \rtrim($this->tmpDir ?: \sys_get_temp_dir(), '/') . '/dup-' . \bin2hex(\random_bytes(8));

        if (!@\mkdir($this->dir, 0700, true)) {
            throw new \RuntimeException("Unable to create temporary directory: $this->dir");
        }

        $chunks = \array_chunk($files, \max(1, (int)\ceil(\count($files) / $threads)));

        for ($thread = 0; $thread < $threads; $thread++) {
            if (\file_put_contents($this->input($thread), \serialize($chunks[$thread] ?? [])) === false) {
                throw new \RuntimeException("Unable to write file list: {$this->input($thread)}");
            }
        }
    }

    public function workerArgs(int $thread, int $chunk): array
    {
        return ['file', $this->input($thread), $this->output($thread), (string)$chunk, (string)$thread];
    }

    public function workerEnv(): array
    {
        return [];
    }

    public function collect(int $threads): HashResult
    {
        $result = new HashResult();

        for ($thread = 0; $thread < $threads; $thread++) {
            $content = @\file_get_contents($this->output($thread));
            $data = $content === false ? false : \unserialize($content, ['allowed_classes' => false]);

            if (!\is_array($data)) {
                throw new \RuntimeException("Missing result of thread $thread");
            }

            $result = $result->merge(new HashResult($data['hashes'], $data['errors']));
        }

        return $result;
    }

    public function close(): void
    {
        if ($this->dir === null) {
            return;
        }

        foreach (\glob("$this->dir/*") ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($this->dir);
        $this->dir = null;
    }

    private function input(int $thread): string
    {
        return "$this->dir/in-$thread.ser";
    }

    private function output(int $thread): string
    {
        return "$this->dir/out-$thread.ser";
    }
}
