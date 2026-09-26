<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Action;

use BlueConsole\Style;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class Deleter
{
    private int $files = 0;
    private int $size = 0;

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

    public function delete(string $file): bool
    {
        $name = OutputFormatter::escape($file);
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
