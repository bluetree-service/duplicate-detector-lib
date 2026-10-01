<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test\Action;

use BlueConsole\MultiSelect;
use BlueDuplicateDetector\Action\AutoDelete;
use BlueDuplicateDetector\Action\DeletePolicy;
use BlueDuplicateDetector\Action\Deleter;
use BlueDuplicateDetector\Action\Interactive;
use BlueDuplicateDetector\Action\ListOnly;
use BlueDuplicateDetector\DuplicateGroup;
use BlueDuplicateDetector\Grouper;
use BlueDuplicateDetector\Test\Fixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class ActionsTest extends TestCase
{
    private string $dir;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->dir = Fixture::create(Fixture::STANDARD + ['z/<info>tag.txt' => 'same content']);
        $this->output = new BufferedOutput();
    }

    protected function tearDown(): void
    {
        Fixture::remove($this->dir);
    }

    private function group(): DuplicateGroup
    {
        // unsorted on purpose, Grouper sorts: a/one, b/two, b/c/three, z/<info>tag
        return (new Grouper())->group(['h' => [
            "$this->dir/z/<info>tag.txt",
            "$this->dir/b/two.txt",
            "$this->dir/a/one.txt",
            "$this->dir/b/c/three.txt",
        ]])[0];
    }

    public function testListOnlyPrintsEscapedPathsWithSizes(): void
    {
        (new ListOnly(Fixture::style($this->output)))->handle($this->group());

        $text = $this->output->fetch();
        $this->assertStringContainsString("$this->dir/z/<info>tag.txt (12.00 B)", $text);
        $this->assertStringContainsString("$this->dir/a/one.txt (12.00 B)", $text);
    }

    public function testListOnlyWithoutSizes(): void
    {
        (new ListOnly(Fixture::style($this->output), false))->handle($this->group());

        $this->assertStringNotContainsString('B)', $this->output->fetch());
    }

    public function testInteractiveDeletesFileShownAtSelectedPosition(): void
    {
        $group = $this->group();
        $select = $this->createMock(MultiSelect::class);
        $select->expects($this->once())
            ->method('renderMultiSelect')
            ->with($this->callback(fn (array $options) => \str_starts_with($options[1], "$this->dir/b/two.txt")))
            ->willReturn([1 => true]);

        $style = Fixture::style($this->output);
        (new Interactive($style, $select, new Deleter($style)))->handle($group);

        $this->assertFileDoesNotExist("$this->dir/b/two.txt");
        $this->assertFileExists("$this->dir/a/one.txt");
        $this->assertFileExists("$this->dir/b/c/three.txt");
    }

    public function testInteractiveRefusesToDeleteAllCopies(): void
    {
        $select = $this->createMock(MultiSelect::class);
        $select->method('renderMultiSelect')->willReturn([0 => true, 1 => true, 2 => true, 3 => true]);

        $style = Fixture::style($this->output);
        $deleter = new Deleter($style);
        (new Interactive($style, $select, $deleter))->handle($this->group());

        $this->assertSame(0, $deleter->deletedFiles());
        $this->assertFileExists("$this->dir/a/one.txt");
        $this->assertStringContainsString('All copies selected', $this->output->fetch());
    }

    public function testInteractiveKeepSelectedDeletesOthers(): void
    {
        $select = $this->createMock(MultiSelect::class);
        $select->method('renderMultiSelect')->willReturn([0 => true]);

        $style = Fixture::style($this->output);
        (new Interactive($style, $select, new Deleter($style), true))->handle($this->group());

        $this->assertFileExists("$this->dir/a/one.txt");
        $this->assertFileDoesNotExist("$this->dir/b/two.txt");
        $this->assertFileDoesNotExist("$this->dir/b/c/three.txt");
        $this->assertFileDoesNotExist("$this->dir/z/<info>tag.txt");
    }

    public function testInteractiveKeepSelectedWithNothingSelectedDeletesNothing(): void
    {
        $select = $this->createMock(MultiSelect::class);
        $select->method('renderMultiSelect')->willReturn([]);

        $style = Fixture::style($this->output);
        $deleter = new Deleter($style);
        (new Interactive($style, $select, $deleter, true))->handle($this->group());

        $this->assertSame(0, $deleter->deletedFiles());
        $this->assertStringContainsString('No copy selected to keep', $this->output->fetch());
    }

    public function testAutoDeleteKeepsFirstSortedFile(): void
    {
        $style = Fixture::style($this->output);
        $deleter = new Deleter($style);

        (new AutoDelete($style, new DeletePolicy(), $deleter))->handle($this->group());

        $this->assertFileExists("$this->dir/a/one.txt");
        $this->assertFileDoesNotExist("$this->dir/b/two.txt");
        $this->assertFileDoesNotExist("$this->dir/b/c/three.txt");
        $this->assertSame(3, $deleter->deletedFiles());
    }
}
