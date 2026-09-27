<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Progress;

final class NullProgress implements Progress
{
    public function start(int $max, int $threads = 0): void
    {
    }

    public function advance(string $message = ''): void
    {
    }

    public function thread(int $thread, int $done, ?int $max): void
    {
    }

    public function finish(): void
    {
    }
}
