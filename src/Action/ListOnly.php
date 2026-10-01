<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Action;

use BlueConsole\Style;
use BlueData\Data\Formats;
use BlueDuplicateDetector\DuplicateGroup;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class ListOnly implements Action
{
    public function __construct(
        private readonly Style $style,
        private readonly bool $withSize = true,
    ) {
    }

    public function handle(DuplicateGroup $group): void
    {
        // paths only (-l) stay plain for piping
        if (!$this->withSize) {
            foreach ($group->files as $file) {
                $this->style->writeln(OutputFormatter::escape($file));
            }

            return;
        }

        $this->style->writeln('<fg=gray>#' . OutputFormatter::escape($group->key) . '</>');

        // first file is the one kept by default delete policy
        foreach ($group->files as $index => $file) {
            $this->style->writeln(
                '<fg=' . ($index === 0 ? 'green' : 'yellow') . '>' . OutputFormatter::escape($file) . '</>'
                . ' (' . Formats::dataSize($group->sizes[$file]) . ')'
            );
        }

        $this->style->newLine();
    }
}
