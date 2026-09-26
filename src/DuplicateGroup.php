<?php

declare(strict_types=1);

namespace BlueDuplicateDetector;

final class DuplicateGroup
{
    /**
     * @var array<string, int> path => size in bytes (0 when unreadable)
     */
    public readonly array $sizes;

    public readonly int $size;

    /**
     * @param string $key hash or file name (check by name)
     * @param string[] $files
     */
    public function __construct(
        public readonly string $key,
        public readonly array $files,
    ) {
        $sizes = [];

        foreach ($files as $file) {
            $sizes[$file] = (int)@\filesize($file);
        }

        $this->sizes = $sizes;
        $this->size = \array_sum($sizes);
    }
}
