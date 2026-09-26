<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

final class FileHash
{
    public const ALGORITHM = 'sha3-256';

    /**
     * @throws \RuntimeException
     */
    public static function of(string $file, int $chunk = 0): string
    {
        if ($chunk > 0) {
            $content = @\file_get_contents($file, false, null, 0, $chunk);
            $hash = $content === false ? false : \hash(self::ALGORITHM, $content);
        } else {
            $hash = @\hash_file(self::ALGORITHM, $file);
        }

        if ($hash === false) {
            throw new \RuntimeException("Unable to read file: $file");
        }

        return $hash;
    }

    /**
     * Shared by SingleProcess and bin/hash-worker.php.
     *
     * @param iterable<string> $files
     * @param callable(string, int): void $afterFile called with file and number of processed files
     */
    public static function hashList(iterable $files, int $chunk, callable $afterFile): HashResult
    {
        $hashes = [];
        $errors = [];
        $done = 0;

        foreach ($files as $file) {
            try {
                $hashes[self::of($file, $chunk)][] = $file;
            } catch (\RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }

            $afterFile($file, ++$done);
        }

        return new HashResult($hashes, $errors);
    }
}
