<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Action;

use BlueDuplicateDetector\DuplicateGroup;

interface Action
{
    public function handle(DuplicateGroup $group): void;
}
