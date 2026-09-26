<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Progress;

final class NullProgress implements Progress
{
    public function start(int $max): void
    {
    }

    public function advance(string $message = ''): void
    {
    }

    public function finish(): void
    {
    }
}
