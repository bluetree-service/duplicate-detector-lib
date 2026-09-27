<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Progress;

interface Progress
{
    /**
     * @param int $threads number of worker processes, 0 = hashing in current process
     */
    public function start(int $max, int $threads = 0): void;

    public function advance(string $message = ''): void;

    /**
     * @param int|null $max files assigned to the thread, null when unknown (shared Redis queue)
     */
    public function thread(int $thread, int $done, ?int $max): void;

    public function finish(): void;
}
