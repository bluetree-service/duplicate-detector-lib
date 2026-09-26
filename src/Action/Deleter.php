<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Action;

use BlueConsole\Style;
use BlueDuplicateDetector\Hasher\FileHash;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class Deleter
{
    private int $files = 0;
    private int $size = 0;

    /**
     * @var array<string, string> kept file => full hash
     */
    private array $keptHashes = [];

    /**
     * @param string|null $backupDir copy file to $backupDir + its absolute path before delete
     * @param bool $dryRun do everything (backup included) except delete
     */
    public function __construct(
        private readonly Style $style,
        private readonly ?string $backupDir = null,
        private readonly bool $dryRun = false,
    ) {
    }

    /**
     * @param string[] $kept files of the same group that stay, $file is deleted only when one of them
     *                       is a different file with identical full content
     */
    public function delete(string $file, array $kept): bool
    {
        $name = OutputFormatter::escape($file);
        $reason = $this->unsafe($file, $kept);

        if ($reason !== null) {
            $this->style->errorMessage("<fg=red>Remove</> skipped, $reason: $name");
            return false;
        }

        $size = (int)@\filesize($file);

        if ($this->backupDir !== null && !$this->backup($file)) {
            $this->style->errorMessage("<fg=blue>Copy</> failed, file kept: $name");
            return false;
        }

        if (!$this->dryRun && !@\unlink($file)) {
            $this->style->errorMessage("<fg=red>Remove</> failed: $name");
            return false;
        }

        $this->style->okMessage('<fg=red>Removed</>' . ($this->dryRun ? ' (test)' : '') . ": $name");
        $this->files++;
        $this->size += $size;

        return true;
    }

    public function deletedFiles(): int
    {
        return $this->files;
    }

    public function deletedSize(): int
    {
        return $this->size;
    }

    /**
     * Last check before every delete: chunk and by-name groups are not compared by full content, and files
     * may change or vanish after hashing, so the only copy of some content must never be removed.
     */
    private function unsafe(string $file, array $kept): ?string
    {
        $stat = @\stat($file);

        if ($stat === false || !\is_file($file)) {
            return 'file not found';
        }

        $copies = \array_values(\array_filter($kept, static fn (string $copy): bool => \is_file($copy)));

        if ($copies === []) {
            return 'no kept copy exists';
        }

        foreach ($copies as $copy) {
            $copyStat = \stat($copy);

            if ($copyStat['dev'] === $stat['dev'] && $copyStat['ino'] === $stat['ino']) {
                return 'same file as kept ' . OutputFormatter::escape($copy);
            }
        }

        // ponytail: full re-read of each deleted file (kept hashes cached), skip when hashing is the bottleneck
        try {
            $hash = FileHash::of($file);

            foreach ($copies as $copy) {
                if (($this->keptHashes[$copy] ??= FileHash::of($copy)) === $hash) {
                    return null;
                }
            }
        } catch (\RuntimeException $exception) {
            return OutputFormatter::escape($exception->getMessage());
        }

        return 'content differs from kept copy';
    }

    private function backup(string $file): bool
    {
        $source = \realpath($file);

        if ($source === false) {
            return false;
        }

        $destination = \rtrim($this->backupDir, '/') . $source;
        $directory = \dirname($destination);

        if (!\is_dir($directory) && !@\mkdir($directory, 0777, true)) {
            return false;
        }

        // backup dir "/" (or a symlink to it) would copy the file onto itself and then delete it
        if (\realpath($directory) === \dirname($source)) {
            return false;
        }

        if (!@\copy($source, $destination)) {
            return false;
        }

        $this->style->okMessage(
            '<fg=blue>Copy</>: ' . OutputFormatter::escape($file) . ' to ' . OutputFormatter::escape($destination)
        );

        return true;
    }
}
