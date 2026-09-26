<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test\Action;

use BlueDuplicateDetector\Action\Deleter;
use BlueDuplicateDetector\Test\Fixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class DeleterTest extends TestCase
{
    private string $dir;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->dir = Fixture::create(Fixture::STANDARD);
        $this->output = new BufferedOutput();
    }

    protected function tearDown(): void
    {
        Fixture::remove($this->dir);
    }

    public function testDeletesAndCounts(): void
    {
        $deleter = new Deleter(Fixture::style($this->output));

        $this->assertTrue($deleter->delete("$this->dir/a/one.txt"));
        $this->assertFileDoesNotExist("$this->dir/a/one.txt");
        $this->assertSame(1, $deleter->deletedFiles());
        $this->assertSame(12, $deleter->deletedSize());
    }

    public function testDryRunKeepsFileButCounts(): void
    {
        $deleter = new Deleter(Fixture::style($this->output), null, true);

        $this->assertTrue($deleter->delete("$this->dir/a/one.txt"));
        $this->assertFileExists("$this->dir/a/one.txt");
        $this->assertSame(1, $deleter->deletedFiles());
        $this->assertStringContainsString('(test)', $this->output->fetch());
    }

    public function testBackupCopiesWithFullPath(): void
    {
        $backup = "$this->dir/backup";
        $file = "$this->dir/a/one.txt";
        $real = \realpath($file);

        (new Deleter(Fixture::style($this->output), $backup))->delete($file);

        $this->assertFileDoesNotExist($file);
        $this->assertStringEqualsFile($backup . $real, 'same content');
    }

    public function testFailedBackupKeepsFile(): void
    {
        \file_put_contents("$this->dir/not-a-dir", 'x');

        $deleter = new Deleter(Fixture::style($this->output), "$this->dir/not-a-dir");

        $this->assertFalse($deleter->delete("$this->dir/a/one.txt"));
        $this->assertFileExists("$this->dir/a/one.txt");
        $this->assertSame(0, $deleter->deletedFiles());
    }

    public function testBackupOntoSourceKeepsFile(): void
    {
        $deleter = new Deleter(Fixture::style($this->output), '/');

        $this->assertFalse($deleter->delete("$this->dir/a/one.txt"));
        $this->assertStringEqualsFile("$this->dir/a/one.txt", 'same content');
    }
}
