<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

use BlueDuplicateDetector\Progress\Progress;

final class SingleProcess implements Hasher
{
    public function hash(array $files, int $chunk, Progress $progress): HashResult
    {
        $progress->start(\count($files));

        try {
            return FileHash::hashList(
                $files,
                $chunk,
                static fn (string $file) => $progress->advance($file)
            );
        } finally {
            $progress->finish();
        }
    }
}
