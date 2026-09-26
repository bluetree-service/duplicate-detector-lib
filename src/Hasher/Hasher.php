<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

use BlueDuplicateDetector\Progress\Progress;

interface Hasher
{
    /**
     * @param string[] $files
     * @param int $chunk hash only first $chunk bytes, 0 = whole file
     */
    public function hash(array $files, int $chunk, Progress $progress): HashResult;
}
