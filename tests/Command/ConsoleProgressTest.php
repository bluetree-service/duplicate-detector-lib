<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test\Command;

use BlueDuplicateDetector\Command\ConsoleProgress;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
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

    public function testTerminalShowsBarForEveryThreadAndErasesThemAll(): void
    {
        $output = new class (\fopen('php://memory', 'rwb'), StreamOutput::VERBOSITY_NORMAL, true) extends StreamOutput implements ConsoleOutputInterface {
            private array $sections = [];

            public function getErrorOutput(): OutputInterface
            {
                return $this;
            }

            public function setErrorOutput(OutputInterface $error): void
            {
            }

            public function section(): ConsoleSectionOutput
            {
                return new ConsoleSectionOutput($this->getStream(), $this->sections, $this->getVerbosity(), true, $this->getFormatter());
            }
        };

        $progress = new ConsoleProgress($output);
        $progress->start(6, 2);
        \usleep(50_000); // bars redraw at most every 40 ms
        $progress->thread(1, 2, 3);
        $progress->thread(0, 1, null);
        $progress->advance();

        \rewind($output->getStream());
        $running = \stream_get_contents($output->getStream());

        $this->assertMatchesRegularExpression('/Thread 0: +1\b/', $running);
        $this->assertMatchesRegularExpression('/Thread 1: +2\/3/', $running);

        $progress->finish();
        $output->writeln('next');

        \rewind($output->getStream());
        $out = \stream_get_contents($output->getStream());

        $this->assertSame('next' . \PHP_EOL, \substr($out, \strrpos($out, "\x1b[0J") + 4));
    }
}
