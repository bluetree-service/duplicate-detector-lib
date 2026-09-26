<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

final class HashResult
{
    /**
     * @param array<string, string[]> $hashes hash => files
     * @param string[] $errors
     */
    public function __construct(
        public readonly array $hashes = [],
        public readonly array $errors = [],
    ) {
    }

    public function merge(HashResult $other): self
    {
        $hashes = $this->hashes;

        // no array_merge_recursive: numeric-looking hash keys would be renumbered
        foreach ($other->hashes as $hash => $files) {
            $hashes[$hash] = [...($hashes[$hash] ?? []), ...$files];
        }

        return new self($hashes, [...$this->errors, ...$other->errors]);
    }
}
