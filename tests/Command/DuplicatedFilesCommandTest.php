<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test\Command;

use BlueDuplicateDetector\Command\DuplicatedFilesCommand;
use BlueDuplicateDetector\Test\Fixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class DuplicatedFilesCommandTest extends TestCase
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

    private function runCommand(array $input, array $defaultSources = []): CommandTester
    {
        $tester = new CommandTester(new DuplicatedFilesCommand('duplicate', $defaultSources));
        $tester->execute($input, ['decorated' => false]);

        return $tester;
    }

    public function testListOnly(): void
    {
        $tester = $this->runCommand(['source' => [$this->dir], '--list-only' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString("$this->dir/b/c/three.txt", $tester->getDisplay());
        $this->assertStringContainsString('Duplicated files: 7', $tester->getDisplay());
    }

    public function testThreadsGiveSameSummary(): void
    {
        $tester = $this->runCommand(['source' => [$this->dir], '--thread' => '3', '--skip-empty' => true]);

        $this->assertStringContainsString('Duplicated files: 5', $tester->getDisplay());
    }

    public function testDefaultSources(): void
    {
        $tester = $this->runCommand(['--size' => true], [$this->dir]);

        $this->assertStringContainsString('Duplicated files: 7', $tester->getDisplay());
    }

    public function testCheckByName(): void
    {
        $dir = Fixture::create(['a/photo-001.jpg' => '1', 'b/photo-002.jpg' => '2', 'c/report.pdf' => '3']);

        try {
            $tester = $this->runCommand(['source' => [$dir], '--check-by-name' => '90']);

            $this->assertStringContainsString('Duplicated files: 2', $tester->getDisplay());
        } finally {
            Fixture::remove($dir);
        }
    }

    public function testHtmlReport(): void
    {
        $out = "$this->dir/../" . \basename($this->dir) . '-html';

        try {
            $this->runCommand(['source' => [$this->dir], '--html' => $out]);

            $this->assertFileExists("$out/index.html");
            $this->assertFileExists("$out/duplicates-0001.html");
        } finally {
            Fixture::remove($out);
        }
    }

    public function testAutoDeleteLeavesOneFilePerGroup(): void
    {
        $this->runCommand(['source' => [$this->dir], '--auto-delete' => true]);

        $this->assertFileExists("$this->dir/a/one.txt");
        $this->assertFileDoesNotExist("$this->dir/b/two.txt");
        $this->assertFileDoesNotExist("$this->dir/b/c/three.txt");
        $this->assertFileExists("$this->dir/e/unique.txt");
        $this->assertFileExists("$this->dir/g/prefix-2.bin");
    }

    public function testAutoDeleteTestModeKeepsFiles(): void
    {
        $tester = $this->runCommand(['source' => [$this->dir], '--auto-delete' => true, '--auto-delete-test' => true]);

        $this->assertFileExists("$this->dir/b/two.txt");
        $this->assertStringContainsString('Removed (test)', $tester->getDisplay());
    }

    public function testDeletePolicyExample(): void
    {
        $tester = $this->runCommand(['--delete-policy-example' => true]);

        $this->assertStringContainsString('"keep_rule"', $tester->getDisplay());
    }

    public function testInvalidPolicyFailsBeforeDeleting(): void
    {
        \file_put_contents("$this->dir/policy.json", '{"keep_rule": {"filename_is": "/[/"}}');

        try {
            $this->runCommand(['source' => [$this->dir], '--auto-delete' => true, '--delete-policy' => "$this->dir/policy.json"]);
            $this->fail('Exception expected');
        } catch (\InvalidArgumentException) {
            $this->assertFileExists("$this->dir/b/two.txt");
        }
    }

    public static function invalidInput(): array
    {
        return [
            'interactive + list-only' => [['--interactive' => true, '--list-only' => true], 'incompatible'],
            'interactive + auto-delete' => [['--interactive' => true, '--auto-delete' => true], 'incompatible'],
            'policy without auto-delete' => [['--delete-policy' => 'x.json'], 'require --auto-delete'],
            'test without auto-delete' => [['--auto-delete-test' => true], 'require --auto-delete'],
            'redis without threads' => [['--redis' => null], '--thread'],
            'bad thread' => [['--thread' => 'abc'], '--thread'],
            'negative chunk' => [['--chunk' => '-1'], '--chunk'],
            'similarity over 100' => [['--check-by-name' => '150'], '--check-by-name'],
            'bad redis address' => [['--thread' => '2', '--redis' => 'a:b:c'], 'Invalid Redis address'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInput')]
    public function testInvalidInputThrows(array $input, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->runCommand(['source' => [$this->dir]] + $input);
    }

    public function testMissingSourceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->runCommand([]);
    }

    public function testCustomName(): void
    {
        $this->assertSame('fs:duplicated', (new DuplicatedFilesCommand('fs:duplicated'))->getName());
    }
}
