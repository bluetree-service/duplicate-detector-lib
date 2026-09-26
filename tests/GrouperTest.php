<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test;

use BlueDuplicateDetector\Grouper;
use PHPUnit\Framework\TestCase;

class GrouperTest extends TestCase
{
    public function testDropsSingleFilesAndDuplicatedPaths(): void
    {
        $groups = (new Grouper())->group([
            'h1' => ['/x/a', '/x/a'],
            'h2' => ['/x/b'],
            'h3' => ['/x/c', '/y/c'],
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame('h3', $groups[0]->key);
        $this->assertSame(['/x/c', '/y/c'], $groups[0]->files);
    }

    public function testSortsFilesByDirectoryThenNameAndGroupsByDirectories(): void
    {
        $groups = (new Grouper())->group([
            'h1' => ['/b/file10', '/a/z', '/b/file2'],
            'h2' => ['/a/y', '/A/x'],
            '123' => ['/c/1', '/c/2'],
        ]);

        $this->assertSame(['/a/z', '/b/file2', '/b/file10'], $groups[1]->files);
        $this->assertSame(['/A/x', '/a/y'], $groups[0]->files);
        $this->assertSame('123', $groups[2]->key);
    }

    public function testComputesSizes(): void
    {
        $dir = Fixture::create(['a' => '12345', 'b' => '12345']);

        try {
            $group = (new Grouper())->group(['h' => ["$dir/a", "$dir/b"]])[0];

            $this->assertSame(["$dir/a" => 5, "$dir/b" => 5], $group->sizes);
            $this->assertSame(10, $group->size);
        } finally {
            Fixture::remove($dir);
        }
    }
}
