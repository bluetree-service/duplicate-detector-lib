<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Command;

use BlueDuplicateDetector\Progress\Progress;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleProgress implements Progress
{
    private const FORMAT = ' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%';

    private ?ProgressBar $bar = null;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly bool $showMessage = false,
    ) {
    }

    public function start(int $max): void
    {
        $this->bar = new ProgressBar($this->output, $max);
        $this->bar->setFormat(self::FORMAT . ($this->showMessage ? ' %message%' : ''));
        $this->bar->setMessage('');
        $this->bar->start();
    }

    public function advance(string $message = ''): void
    {
        if ($this->showMessage) {
            $this->bar?->setMessage(OutputFormatter::escape($message));
        }

        $this->bar?->advance();
    }

    public function finish(): void
    {
        $this->bar?->finish();
        $this->bar = null;
        $this->output->writeln('');
    }
}
