<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Action;

use BlueConsole\Style;
use BlueDuplicateDetector\DuplicateGroup;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class AutoDelete implements Action
{
    public function __construct(
        private readonly Style $style,
        private readonly DeletePolicy $policy,
        private readonly Deleter $deleter,
    ) {
    }

    public function handle(DuplicateGroup $group): void
    {
        $decision = $this->policy->decide($group->files);

        foreach ($decision['keep'] as $file) {
            $this->style->okMessage('<fg=green>Keep</>: ' . OutputFormatter::escape($file));
        }

        foreach ($decision['delete'] as $file) {
            $this->deleter->delete($file);
        }

        $this->style->newLine();
    }
}
