<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test\Hasher;

use BlueDuplicateDetector\Hasher\FileTransport;
use BlueDuplicateDetector\Hasher\SingleProcess;
use BlueDuplicateDetector\Hasher\Threads;
use BlueDuplicateDetector\Progress\NullProgress;
use BlueDuplicateDetector\Progress\Progress;
use BlueDuplicateDetector\Scanner;
use BlueDuplicateDetector\Test\Fixture;
use BlueDuplicateDetector\Test\RecordingProgress;
use PHPUnit\Framework\TestCase;

class ThreadsTest extends TestCase
{
    private string $dir;
    private string $tmp;

    protected function setUp(): void
    {
        $this->dir = Fixture::create(Fixture::STANDARD);
        $this->tmp = Fixture::dir();
    }

    protected function tearDown(): void
    {
        Fixture::remove($this->dir);
        Fixture::remove($this->tmp);
    }

    public static function threadsAndChunks(): array
    {
        return [
            'one thread' => [1, 0],
            'three threads' => [3, 0],
            'more threads than files' => [20, 0],
            'chunk' => [3, 7],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('threadsAndChunks')]
    public function testSameResultAsSingleProcess(int $threads, int $chunk): void
    {
        $files = (new Scanner())->scan([$this->dir]);

        $expected = (new SingleProcess())->hash($files, $chunk, new NullProgress());
        $actual = (new Threads($threads, new FileTransport($this->tmp)))->hash($files, $chunk, new NullProgress());

        $this->assertSame(Fixture::normalize($expected->hashes), Fixture::normalize($actual->hashes));
        $this->assertSame([], $actual->errors);
        $this->assertSame([], \glob("$this->tmp/dup-*"), 'session dir removed');
    }

    public function testNonUtf8FileName(): void
    {
        $name = "$this->dir/\xE9t\xE9.txt";

        if (@\file_put_contents($name, 'same content') === false) {
            $this->markTestSkipped('Filesystem does not accept non UTF-8 names.');
        }

        $result = (new Threads(2, new FileTransport($this->tmp)))->hash(
            ["$this->dir/a/one.txt", $name],
            0,
            new NullProgress()
        );

        $this->assertSame(["$this->dir/a/one.txt", $name], $result->hashes[\hash('sha3-256', 'same content')]);
    }

    public function testErrorsFromWorkersAreCollected(): void
    {
        $result = (new Threads(2, new FileTransport($this->tmp)))->hash(
            ["$this->dir/a/one.txt", "$this->dir/missing"],
            0,
            new NullProgress()
        );

        $this->assertSame(["Unable to read file: $this->dir/missing"], $result->errors);
    }

    public function testEmptyListDoesNotStartWorkers(): void
    {
        $result = (new Threads(2, new FileTransport($this->tmp), '/bin/false'))->hash([], 0, new NullProgress());

        $this->assertSame([], $result->hashes);
    }

    public function testFailedWorkerThrowsAndCleansUp(): void
    {
        $threads = new Threads(2, new FileTransport($this->tmp), '/bin/false');

        try {
            $threads->hash(["$this->dir/a/one.txt"], 0, new NullProgress());
            $this->fail('Exception expected');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Hash worker failed', $exception->getMessage());
        }

        $this->assertSame([], \glob("$this->tmp/dup-*"));
    }

    public function testReportsProgressForEveryFile(): void
    {
        $progress = $this->createMock(Progress::class);
        $progress->expects($this->once())->method('start')->with(10, 3);
        $progress->expects($this->exactly(10))->method('advance');
        $progress->expects($this->once())->method('finish');

        (new Threads(3, new FileTransport($this->tmp)))->hash((new Scanner())->scan([$this->dir]), 0, $progress);
    }

    public function testReportsEveryThreadWithItsShareOfFiles(): void
    {
        $progress = new RecordingProgress();

        (new Threads(3, new FileTransport($this->tmp)))->hash((new Scanner())->scan([$this->dir]), 0, $progress);

        $this->assertSame([10, 3], $progress->start);
        $this->assertSame([[4, 4], [4, 4], [2, 2]], $progress->threads);
    }

    public function testThreadsMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Threads(0, new FileTransport());
    }
}
