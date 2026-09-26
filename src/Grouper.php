<?php

declare(strict_types=1);

namespace BlueDuplicateDetector;

final class Grouper
{
    /**
     * Files in group sorted by directory then name, groups sorted by their directory lists.
     *
     * @param array<string|int, string[]> $hashes hash => files
     * @return DuplicateGroup[]
     */
    public function group(array $hashes): array
    {
        $groups = [];

        foreach ($hashes as $key => $files) {
            $files = \array_values(\array_unique($files));

            if (\count($files) < 2) {
                continue;
            }

            \usort($files, [self::class, 'compareFiles']);
            $groups[] = new DuplicateGroup((string)$key, $files);
        }

        // compare directory lists: first directories, on tie second ones etc., then file names
        \usort($groups, static function (DuplicateGroup $first, DuplicateGroup $second): int {
            $count = \min(\count($first->files), \count($second->files));

            for ($i = 0; $i < $count; $i++) {
                $result = \strnatcasecmp(\dirname($first->files[$i]), \dirname($second->files[$i]));

                if ($result !== 0) {
                    return $result;
                }
            }

            return \count($first->files) <=> \count($second->files)
                ?: self::compareFiles($first->files[0], $second->files[0]);
        });

        return $groups;
    }

    public static function compareFiles(string $first, string $second): int
    {
        return \strnatcasecmp(\dirname($first), \dirname($second))
            ?: \strnatcasecmp(\basename($first), \basename($second));
    }
}
