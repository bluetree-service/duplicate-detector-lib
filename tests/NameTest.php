<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test;

use BlueDuplicateDetector\Name;
use PHPUnit\Framework\TestCase;

class NameTest extends TestCase
{
    public function testGroupsSimilarNames(): void
    {
        $groups = (new Name())->group(['/a/photo-001.jpg', '/b/photo-002.jpg', '/c/report.pdf'], 90);

        $this->assertSame(['photo-001.jpg' => ['/a/photo-001.jpg', '/b/photo-002.jpg']], $groups);
    }

    public function testSameNameInManyDirectories(): void
    {
        $groups = (new Name())->group(['/a/file', '/b/file', '/c/file'], 100);

        $this->assertSame(['/a/file', '/b/file', '/c/file'], \array_values(\array_unique($groups['file'])));
    }

    public function testNoMatch(): void
    {
        $this->assertSame([], (new Name())->group(['/a/abc', '/b/xyz'], 50));
    }
}
