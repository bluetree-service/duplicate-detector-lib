<?php

declare(strict_types=1);

namespace BlueDuplicateDetector;

final class Scanner
{
    public function __construct(
        private readonly int $minSize = 0,
        private readonly bool $sameSizeOnly = false,
        private readonly bool $skipEmpty = false,
    ) {
    }

    /**
     * @param string[] $sources directories (read recursively) or files
     * @return string[]
     * @throws \InvalidArgumentException
     */
    public function scan(array $sources): array
    {
        $unique = [];

        foreach ($sources as $source) {
            foreach ($this->read($source) as $file) {
                // same file under two paths (overlapping sources, hard links, case-insensitive fs, double mounts)
                // must not become its own duplicate
                $stat = @\stat($file);
                $unique[$stat === false ? $file : "{$stat['dev']}:{$stat['ino']}"] ??= $file;
            }
        }

        $files = [];
        $sizes = [];

        foreach ($unique as $file) {
            $size = (int)@\filesize($file);

            if ($size < $this->minSize || ($this->skipEmpty && $size === 0)) {
                continue;
            }

            $files[] = $file;
            $sizes[] = $size;
        }

        if ($this->sameSizeOnly) {
            $counts = \array_count_values($sizes);
            $files = \array_values(\array_filter(
                $files,
                static fn (int $index): bool => $counts[$sizes[$index]] > 1,
                ARRAY_FILTER_USE_KEY
            ));
        }

        return $files;
    }

    /**
     * @return iterable<string>
     */
    private function read(string $source): iterable
    {
        if (\is_file($source)) {
            return [$source];
        }

        if (!\is_dir($source)) {
            throw new \InvalidArgumentException("Source not found: $source");
        }

        $directories = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $item): bool => !$item->isDir() || $item->isReadable()
        );

        $files = [];

        foreach (new \RecursiveIteratorIterator($directories) as $item) {
            if ($item->isFile()) {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }
}
