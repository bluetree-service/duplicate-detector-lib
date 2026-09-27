<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test;

use BlueDuplicateDetector\Progress\Progress;

/**
 * Keeps start arguments and last reported state of every thread.
 */
final class RecordingProgress implements Progress
{
    public array $start = [];
    public array $threads = [];

    public function start(int $max, int $threads = 0): void
    {
        $this->start = [$max, $threads];
    }

    public function advance(string $message = ''): void
    {
    }

    public function thread(int $thread, int $done, ?int $max): void
    {
        $this->threads[$thread] = [$done, $max];
        \ksort($this->threads);
    }

    public function finish(): void
    {
    }
}
