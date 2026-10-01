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
        private readonly bool $keepSelected = false,
    ) {
    }

    public function handle(DuplicateGroup $group): void
    {
        $this->style->newLine();
        $this->style->writeln('<fg=gray>#' . OutputFormatter::escape($group->key) . '</>');

        // options are built from $group->files in order, so selected index = index in $group->files
        $options = \array_map(
            static fn (string $file): string => OutputFormatter::escape($file)
                . ' (<info>' . Formats::dataSize($group->sizes[$file]) . '</>)',
            $group->files
        );

        $selected = \array_intersect_key($group->files, $this->select->renderMultiSelect($options));
        $others = \array_diff_key($group->files, $selected);
        [$kept, $deleted] = $this->keepSelected ? [$selected, $others] : [$others, $selected];

        if ($kept === []) {
            $this->style->warningMessage(
                ($this->keepSelected ? 'No copy selected to keep' : 'All copies selected') . ', nothing deleted from this group.'
            );
        } else {
            foreach ($deleted as $file) {
                $this->deleter->delete($file, \array_values($kept));
            }
        }

        $this->style->newLine();
    }
}
