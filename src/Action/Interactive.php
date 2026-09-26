<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Action;

use BlueConsole\MultiSelect;
use BlueConsole\Style;
use BlueData\Data\Formats;
use BlueDuplicateDetector\DuplicateGroup;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class Interactive implements Action
{
    public function __construct(
        private readonly Style $style,
        private readonly MultiSelect $select,
        private readonly Deleter $deleter,
    ) {
    }

    public function handle(DuplicateGroup $group): void
    {
        $this->style->newLine();

        // options are built from $group->files in order, so selected index = index in $group->files
        $options = \array_map(
            static fn (string $file): string => OutputFormatter::escape($file)
                . ' (<info>' . Formats::dataSize($group->sizes[$file]) . '</>)',
            $group->files
        );

        $selected = \array_keys($this->select->renderMultiSelect($options));

        if (\count($selected) >= \count($group->files)) {
            $this->style->warningMessage('All copies selected, nothing deleted from this group.');
        } else {
            $kept = \array_values(\array_diff_key($group->files, \array_flip($selected)));

            foreach ($selected as $index) {
                $this->deleter->delete($group->files[$index], $kept);
            }
        }

        $this->style->newLine();
    }
}
