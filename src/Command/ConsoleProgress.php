<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Command;

use BlueDuplicateDetector\Progress\Progress;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleProgress implements Progress
{
    private const FORMAT = ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%';
    private const THREAD_LABEL = '    <fg=cyan>Thread %d</>: ';

    private ?ProgressBar $bar = null;

    /**
     * @var ProgressBar[] one bar per worker, only on terminal
     */
    private array $threadBars = [];

    /**
     * @var ConsoleSectionOutput[]
     */
    private array $sections = [];

    public function __construct(
        private readonly OutputInterface $output,
        private readonly bool $showMessage = false,
    ) {
    }

    public function start(int $max, int $threads = 0): void
    {
        // every bar in its own section, otherwise redrawing one bar breaks the others
        $withThreads = $threads > 0 && $this->output instanceof ConsoleOutputInterface && $this->output->isDecorated();

        $this->bar = new ProgressBar($withThreads ? $this->section() : $this->output, $max);
        $this->bar->setFormat(self::FORMAT . ($this->showMessage ? ' %message%' : ''));
        $this->bar->setMessage('');
        $this->bar->start();

        for ($thread = 0; $withThreads && $thread < $threads; $thread++) {
            $this->threadBars[$thread] = new ProgressBar($this->section());
            $this->threadBars[$thread]->setFormat(\sprintf(self::THREAD_LABEL, $thread) . '<fg=yellow>%current%</>');
            $this->threadBars[$thread]->start();
        }
    }

    public function advance(string $message = ''): void
    {
        if ($this->showMessage) {
            $this->bar?->setMessage(OutputFormatter::escape($message));
        }

        $this->bar?->advance();
    }

    public function thread(int $thread, int $done, ?int $max): void
    {
        $bar = $this->threadBars[$thread] ?? null;

        if ($bar === null) {
            return;
        }

        if ($max !== null && $bar->getMaxSteps() !== $max) {
            $bar->setMaxSteps($max);
            $bar->setFormat(
                \sprintf(self::THREAD_LABEL, $thread) . '<fg=yellow>%current%/%max%</> [<fg=green>%bar%</>] %percent:3s%%'
            );
        }

        $bar->setProgress($done);
    }

    public function finish(): void
    {
        $this->bar?->finish();

        if ($this->sections !== []) {
            foreach (\array_reverse($this->sections) as $section) {
                $section->clear();
            }
        } elseif ($this->output->isDecorated()) {
            // terminal: erase the bar, next message takes its line; plain output can't erase, just end the line
            $this->bar?->clear();
        } else {
            $this->output->writeln('');
        }

        $this->bar = null;
        $this->threadBars = [];
        $this->sections = [];
    }

    private function section(): ConsoleSectionOutput
    {
        /** @var ConsoleOutputInterface $output */
        $output = $this->output;

        return $this->sections[] = $output->section();
    }
}
