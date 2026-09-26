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
        foreach ($group->files as $file) {
            $size = $this->withSize ? ' (' . Formats::dataSize($group->sizes[$file]) . ')' : '';
            $this->style->writeln(OutputFormatter::escape($file) . $size);
        }

        if ($this->withSize) {
            $this->style->newLine();
        }
    }
}
