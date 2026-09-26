<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

/**
 * How file lists reach worker processes and how their results come back.
 */
interface Transport
{
    /**
     * @param string[] $files
     * @throws \RuntimeException
     */
    public function open(array $files, int $threads): void;

    /**
     * @return string[] arguments passed to bin/hash-worker.php
     */
    public function workerArgs(int $thread, int $chunk): array;

    /**
     * @throws \RuntimeException when result of any thread is missing
     */
    public function collect(int $threads): HashResult;

    /**
     * Remove everything open() created. Safe to call more than once.
     */
    public function close(): void;
}
