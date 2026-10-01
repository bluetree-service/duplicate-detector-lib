<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Action;

use BlueConsole\Style;
use BlueDuplicateDetector\Hasher\FileHash;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class Deleter
{
    public const LINKS = [null, 'hard', 'soft'];

    private int $files = 0;
    private int $size = 0;

    /**
     * @var array<string, string> kept file => full hash
     */
    private array $keptHashes = [];

    /**
     * @param string|null $backupDir copy file to $backupDir + its absolute path before delete
     * @param bool $dryRun do everything (backup included) except delete
     * @param string|null $link hard|soft, replace deleted file with link to kept copy instead of only deleting it
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private readonly Style $style,
        private readonly ?string $backupDir = null,
        private readonly bool $dryRun = false,
        private readonly ?string $link = null,
    ) {
        if (!\in_array($link, self::LINKS, true)) {
            throw new \InvalidArgumentException('Option --link must be hard or soft.');
        }
    }

    /**
     * @param string[] $kept files of the same group that stay, $file is deleted only when one of them
     *                       is a different file with identical full content
     */
    public function delete(string $file, array $kept): bool
    {
        $name = OutputFormatter::escape($file);
        [$copy, $reason] = $this->unsafe($file, $kept);

        if ($reason !== null) {
            $this->style->errorMessage("<fg=red>Remove</> skipped, $reason: $name");
            return false;
        }

        $size = (int)@\filesize($file);

        if ($this->backupDir !== null && !$this->backup($file)) {
            $this->style->errorMessage("<fg=blue>Copy</> failed, file kept: $name");
            return false;
        }

        if ($this->link !== null) {
            if (!$this->dryRun && !$this->replaceWithLink($file, $copy)) {
                $this->style->errorMessage("<fg=red>Link</> failed, file kept: $name");
                return false;
            }

            $this->style->okMessage(
                "<fg=red>Linked</> ($this->link)" . ($this->dryRun ? ' (test)' : '') . ": $name -> "
                . OutputFormatter::escape($copy)
            );
        } elseif (!$this->dryRun && !@\unlink($file)) {
            $this->style->errorMessage("<fg=red>Remove</> failed: $name");
            return false;
        } else {
            $this->style->okMessage('<fg=red>Removed</>' . ($this->dryRun ? ' (test)' : '') . ": $name");
        }

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
     *
     * @return array{0: ?string, 1: ?string} kept copy with identical content, reason when delete is unsafe
     */
    private function unsafe(string $file, array $kept): array
    {
        $stat = @\stat($file);

        if ($stat === false || !\is_file($file)) {
            return [null, 'file not found'];
        }

        $copies = \array_values(\array_filter($kept, static fn (string $copy): bool => \is_file($copy)));

        if ($copies === []) {
            return [null, 'no kept copy exists'];
        }

        foreach ($copies as $copy) {
            $copyStat = \stat($copy);

            if ($copyStat['dev'] === $stat['dev'] && $copyStat['ino'] === $stat['ino']) {
                return [null, 'same file as kept ' . OutputFormatter::escape($copy)];
            }
        }

        // ponytail: full re-read of each deleted file (kept hashes cached), skip when hashing is the bottleneck
        try {
            $hash = FileHash::of($file);

            foreach ($copies as $copy) {
                if (($this->keptHashes[$copy] ??= FileHash::of($copy)) === $hash) {
                    return [$copy, null];
                }
            }
        } catch (\RuntimeException $exception) {
            return [null, OutputFormatter::escape($exception->getMessage())];
        }

        return [null, 'content differs from kept copy'];
    }

    /**
     * Link created under temporary name and renamed over the file, so a failed link never loses the file.
     */
    private function replaceWithLink(string $file, string $copy): bool
    {
        $target = \realpath($copy);
        $temporary = \dirname($file) . '/.dd-link-' . \bin2hex(\random_bytes(6));

        if ($target === false || !($this->link === 'hard' ? @\link($target, $temporary) : @\symlink($target, $temporary))) {
            return false;
        }

        if (!@\rename($temporary, $file)) {
            @\unlink($temporary);
            return false;
        }

        return true;
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
