<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test;

use BlueDuplicateDetector\Scanner;
use PHPUnit\Framework\TestCase;

class ScannerTest extends TestCase
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

    public function testScanReturnsAllFilesRecursively(): void
    {
        $files = (new Scanner())->scan([$this->dir]);

        $this->assertCount(10, $files);
        $this->assertContains("$this->dir/b/c/three.txt", $files);
    }

    public function testAcceptsSingleFileAsSource(): void
    {
        $this->assertSame(["$this->dir/a/one.txt"], (new Scanner())->scan(["$this->dir/a/one.txt"]));
    }

    public function testOverlappingSourcesDoNotDuplicateFiles(): void
    {
        $files = (new Scanner())->scan([$this->dir, "$this->dir/b", $this->dir, "$this->dir/a/one.txt"]);

        $this->assertCount(10, $files);
    }

    public function testSameInodeUnderTwoPathsIsOneFile(): void
    {
        \link("$this->dir/a/one.txt", "$this->dir/a/one-link.txt");

        $files = (new Scanner())->scan([$this->dir]);

        $this->assertCount(10, $files);
    }

    public function testMinSizeFiltersSmallFiles(): void
    {
        $files = (new Scanner(minSize: 12))->scan([$this->dir]);

        $this->assertCount(5, $files);
    }

    public function testSkipEmpty(): void
    {
        $files = (new Scanner(skipEmpty: true))->scan([$this->dir]);

        $this->assertCount(8, $files);
        $this->assertNotContains("$this->dir/f/empty1", $files);
    }

    public function testSameSizeOnlyDropsFilesWithUniqueSize(): void
    {
        $files = (new Scanner(sameSizeOnly: true))->scan([$this->dir]);

        $this->assertCount(9, $files);
        $this->assertNotContains("$this->dir/e/unique.txt", $files);
    }

    public function testSameSizeOnlyAppliesAfterOtherFilters(): void
    {
        $dir = Fixture::create(['x/a' => '', 'x/b' => '', 'x/c' => '1']);

        try {
            $this->assertSame([], (new Scanner(sameSizeOnly: true, skipEmpty: true))->scan([$dir]));
        } finally {
            Fixture::remove($dir);
        }
    }

    public function testExcludeSkipsDirectoryByNameOnAnyLevel(): void
    {
        $this->assertCount(9, (new Scanner(exclude: ['c']))->scan([$this->dir]));
        $this->assertCount(8, (new Scanner(exclude: ['b']))->scan([$this->dir]));
    }

    public function testExcludeSkipsDirectoryByPath(): void
    {
        $files = (new Scanner(exclude: ["$this->dir/b/c"]))->scan([$this->dir]);

        $this->assertCount(9, $files);
        $this->assertContains("$this->dir/b/two.txt", $files);
        $this->assertCount(8, (new Scanner(exclude: ['*/b']))->scan([$this->dir]));
    }

    public function testExcludeDoesNotMatchFiles(): void
    {
        $this->assertCount(10, (new Scanner(exclude: ['one.txt']))->scan([$this->dir]));
    }

    public function testExcludeIsCaseSensitiveUnlessIgnoreCase(): void
    {
        $this->assertCount(10, (new Scanner(exclude: ['B']))->scan([$this->dir]));
        $this->assertCount(8, (new Scanner(exclude: ['B'], ignoreCase: true))->scan([$this->dir]));
    }

    public function testIncludeKeepsOnlyMatchingFileNames(): void
    {
        $this->assertCount(6, (new Scanner(include: ['*.txt']))->scan([$this->dir]));
        $this->assertCount(8, (new Scanner(include: ['*.bin', '*.txt']))->scan([$this->dir]));
    }

    public function testIncludeIsCaseSensitiveUnlessIgnoreCase(): void
    {
        $this->assertCount(0, (new Scanner(include: ['*.TXT']))->scan([$this->dir]));
        $this->assertCount(6, (new Scanner(include: ['*.TXT'], ignoreCase: true))->scan([$this->dir]));
    }

    public function testFiltersDoNotApplyToFileGivenAsSource(): void
    {
        $scanner = new Scanner(exclude: ['a'], include: ['*.bin']);

        $this->assertSame(["$this->dir/a/one.txt"], $scanner->scan(["$this->dir/a/one.txt"]));
    }

    public function testMissingSourceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Source not found');

        (new Scanner())->scan(["$this->dir/missing"]);
    }
}
