<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test\Report;

use BlueDuplicateDetector\Grouper;
use BlueDuplicateDetector\Report\HtmlReport;
use BlueDuplicateDetector\Test\Fixture;
use PHPUnit\Framework\TestCase;

class HtmlReportTest extends TestCase
{
    private string $dir;
    private string $out;

    protected function setUp(): void
    {
        $this->dir = Fixture::create(['a/1' => 'x', 'b/1' => 'x', 'c/<b>2' => 'y', 'd/2' => 'y', 'e/3' => 'z', 'f/3' => 'z']);
        $this->out = "$this->dir/report";
    }

    protected function tearDown(): void
    {
        Fixture::remove($this->dir);
    }

    private function duplicateGroups(): array
    {
        $d = $this->dir;

        return (new Grouper())->group([
            'h3' => ["$d/f/3", "$d/e/3"],
            'h1' => ["$d/b/1", "$d/a/1"],
            'h2' => ["$d/d/2", "$d/c/<b>2"],
        ]);
    }

    public function testWritesPagesAndIndex(): void
    {
        $pages = (new HtmlReport(2))->save($this->duplicateGroups(), $this->out);

        $this->assertSame(2, $pages);
        $this->assertFileExists("$this->out/index.html");
        $this->assertFileExists("$this->out/duplicates-0001.html");
        $this->assertFileExists("$this->out/duplicates-0002.html");

        $first = \file_get_contents("$this->out/duplicates-0001.html");
        $this->assertLessThan(\strpos($first, "$this->dir/c/"), \strpos($first, "$this->dir/a/1"));
        $this->assertStringContainsString('&lt;b&gt;2', $first);
        $this->assertStringNotContainsString('<b>2', $first);
        $this->assertStringContainsString('Duplications: <b>3</b>', \file_get_contents("$this->out/index.html"));
    }

    public function testRemovesOldPages(): void
    {
        \mkdir($this->out);
        \file_put_contents("$this->out/duplicates-0009.html", 'old');

        (new HtmlReport(2))->save($this->duplicateGroups(), $this->out);

        $this->assertFileDoesNotExist("$this->out/duplicates-0009.html");
    }

    public function testEmptyGroupsWriteOnlyIndex(): void
    {
        $this->assertSame(0, (new HtmlReport())->save([], $this->out));
        $this->assertFileExists("$this->out/index.html");
    }

    public function testUncreatableDirectoryThrows(): void
    {
        \file_put_contents("$this->dir/file", 'x');

        $this->expectException(\RuntimeException::class);

        (new HtmlReport())->save($this->duplicateGroups(), "$this->dir/file/report");
    }
}
