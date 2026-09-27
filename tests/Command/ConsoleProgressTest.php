<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test\Command;

use BlueDuplicateDetector\Command\ConsoleProgress;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\StreamOutput;

class ConsoleProgressTest extends TestCase
{
    private function render(bool $decorated): string
    {
        $output = new StreamOutput(\fopen('php://memory', 'rwb'), StreamOutput::VERBOSITY_NORMAL, $decorated);
        $progress = new ConsoleProgress($output);
        $progress->start(3);
        $progress->advance();
        $progress->advance();
        $progress->advance();
        $progress->finish();
        $output->writeln('next');

        \rewind($output->getStream());

        return \stream_get_contents($output->getStream());
    }

    public function testFinishedBarIsErasedFromTerminal(): void
    {
        $out = $this->render(true);
        $afterLastErase = \substr($out, \strrpos($out, "\x1b[2K") + 4);

        $this->assertSame('next' . \PHP_EOL, \ltrim($afterLastErase, "\r"));
    }

    public function testNextLineStartsOnNewLineWithoutDecoration(): void
    {
        $this->assertMatchesRegularExpression("/100%[^\n]*\nnext\n\z/", $this->render(false));
    }
}
