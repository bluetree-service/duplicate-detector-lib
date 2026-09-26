<?php

declare(strict_types=1);

namespace BlueDuplicateDetector;

final class Name
{
    /**
     * @param string[] $files
     * @param int $similarity minimal similar_text() percent (0-100)
     * @return array<string, string[]> file name => paths with similar names
     */
    public function group(array $files, int $similarity): array
    {
        // ponytail: O(n²) similar_text over all pairs, fine for thousands of files, bucket by name prefix if slow
        $names = [];

        foreach ($files as $file) {
            $names[$file] = \basename($file);
        }

        $groups = [];

        foreach ($names as $path => $name) {
            unset($names[$path]);

            foreach ($names as $otherPath => $otherName) {
                \similar_text($name, $otherName, $percent);

                if ($percent >= $similarity) {
                    $groups[$name] ??= [$path];
                    $groups[$name][] = $otherPath;
                }
            }
        }

        return $groups;
    }
}
