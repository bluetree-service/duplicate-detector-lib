<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test\Hasher;

use BlueDuplicateDetector\Hasher\SingleProcess;
use BlueDuplicateDetector\Progress\NullProgress;
use BlueDuplicateDetector\Progress\Progress;
use BlueDuplicateDetector\Scanner;
use BlueDuplicateDetector\Test\Fixture;
use PHPUnit\Framework\TestCase;

class SingleProcessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Fixture::create(Fixture::STANDARD);
    }

    protected function tearDown(): void
    {
        Fixture::remove($this->dir);
    }

    public function testGroupsFilesByContentHash(): void
    {
        $result = (new SingleProcess())->hash((new Scanner())->scan([$this->dir]), 0, new NullProgress());

        $this->assertCount(6, $result->hashes);
        $this->assertSame([], $result->errors);
        $this->assertCount(3, $result->hashes[\hash('sha3-256', 'same content')]);
    }

    public function testChunkHashesOnlyFileBeginning(): void
    {
        $result = (new SingleProcess())->hash((new Scanner())->scan([$this->dir]), 7, new NullProgress());

        $this->assertCount(5, $result->hashes);
        $this->assertCount(2, $result->hashes[\hash('sha3-256', 'PREFIX-')]);
    }

    public function testUnreadableFileIsReportedAndSkipped(): void
    {
        $result = (new SingleProcess())->hash(["$this->dir/a/one.txt", "$this->dir/missing"], 0, new NullProgress());

        $this->assertCount(1, $result->hashes);
        $this->assertSame(["Unable to read file: $this->dir/missing"], $result->errors);
    }

    public function testReportsProgressPerFile(): void
    {
        $progress = $this->createMock(Progress::class);
        $progress->expects($this->once())->method('start')->with(2);
        $progress->expects($this->exactly(2))->method('advance');
        $progress->expects($this->once())->method('finish');

        (new SingleProcess())->hash(["$this->dir/a/one.txt", "$this->dir/b/two.txt"], 0, $progress);
    }
}
