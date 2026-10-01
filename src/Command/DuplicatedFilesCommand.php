<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Command;

use BlueConsole\MultiSelect;
use BlueConsole\Style;
use BlueData\Data\Formats;
use BlueDuplicateDetector\Action\Action;
use BlueDuplicateDetector\Action\AutoDelete;
use BlueDuplicateDetector\Action\DeletePolicy;
use BlueDuplicateDetector\Action\Deleter;
use BlueDuplicateDetector\Action\Interactive;
use BlueDuplicateDetector\Action\ListOnly;
use BlueDuplicateDetector\DuplicateGroup;
use BlueDuplicateDetector\Grouper;
use BlueDuplicateDetector\Hasher\FileTransport;
use BlueDuplicateDetector\Hasher\Hasher;
use BlueDuplicateDetector\Hasher\RedisTransport;
use BlueDuplicateDetector\Hasher\SingleProcess;
use BlueDuplicateDetector\Hasher\Threads;
use BlueDuplicateDetector\Name;
use BlueDuplicateDetector\Report\HtmlReport;
use BlueDuplicateDetector\Scanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DuplicatedFilesCommand extends Command
{
    public const DELETE_POLICY_EXAMPLE_FILE = __DIR__ . '/../../etc/delete_policy.json';
    public const HTML_PAGE_SIZE = 100;

    /**
     * @param string[] $defaultSources used when no source argument is given
     * @param array $redis connection used with --redis, keys as RedisTransport::DEFAULTS
     * @throws \InvalidArgumentException invalid Redis connection
     */
    public function __construct(
        string $name = 'duplicate',
        private readonly array $defaultSources = [],
        private readonly string $defaultHtmlDir = '/out',
        private readonly array $redis = [],
    ) {
        RedisTransport::fromArray($redis);
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('Search files duplication and make some action on it.')
            ->addArgument('source', InputArgument::IS_ARRAY, 'Directories or files to check')
            ->addOption('interactive', 'i', InputOption::VALUE_NONE, 'Show multi-checkbox with duplicated files, selected will be deleted (kept with --keep-selected)')
            ->addOption('skip-empty', 's', InputOption::VALUE_NONE, 'Skip empty files')
            ->addOption('exclude', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Skip directories matching pattern (name or full path, e.g. .git, "*/cache*"), repeatable')
            ->addOption('include', 'I', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Check only files with name matching pattern (e.g. "*.jpg"), repeatable')
            ->addOption('ignore-case', 'C', InputOption::VALUE_NONE, 'Case insensitive --exclude and --include patterns')
            ->addOption('check-by-name', 'N', InputOption::VALUE_REQUIRED, 'Compare file names instead of content, value is minimal similarity in percent (0-100)')
            ->addOption('progress-info', 'p', InputOption::VALUE_NONE, 'Show message on progress bar (file name or thread status)')
            ->addOption('thread', 't', InputOption::VALUE_REQUIRED, 'Number of processes calculating hashes, 0 = current process', '0')
            ->addOption('redis', 'r', InputOption::VALUE_NONE, 'Exchange data between processes through Redis instead of temporary files (connection set by application)')
            ->addOption('size', 'S', InputOption::VALUE_NONE, 'Hash only files which size is shared with another file (faster)')
            ->addOption('min-size', 'm', InputOption::VALUE_REQUIRED, 'Minimal size of checked files in bytes', '0')
            ->addOption('chunk', 'c', InputOption::VALUE_REQUIRED, 'Hash only first given bytes of each file (faster for large files, less accurate)', '0')
            ->addOption('list-only', 'l', InputOption::VALUE_NONE, 'Show only paths of duplicated files')
            ->addOption('auto-delete', 'd', InputOption::VALUE_NONE, 'Automatically delete duplicated files, first file of each group (or files matching keep rules) is kept')
            ->addOption('delete-backup', 'b', InputOption::VALUE_REQUIRED, 'Copy deleted files into given directory (keeping absolute path) before delete')
            ->addOption('delete-policy', 'D', InputOption::VALUE_REQUIRED, 'JSON file with keep/delete rules for automatic delete')
            ->addOption('delete-policy-example', 'E', InputOption::VALUE_NONE, 'Print example delete policy file')
            ->addOption('link', 'L', InputOption::VALUE_REQUIRED, 'Replace deleted file with link to kept copy: hard or soft (requires --auto-delete or --interactive)')
            ->addOption('keep-selected', 'k', InputOption::VALUE_NONE, 'Interactive mode: selected files are kept, others deleted')
            ->addOption('auto-delete-test', 'T', InputOption::VALUE_NONE, 'Test automatic delete: apply rules and backup, but do not delete files')
            ->addOption('html', 'H', InputOption::VALUE_OPTIONAL, "Save duplications as HTML pages (index.html + pages by " . self::HTML_PAGE_SIZE . " duplications) in given directory, default {$this->defaultHtmlDir}", false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('delete-policy-example')) {
            $output->write((string)\file_get_contents(self::DELETE_POLICY_EXAMPLE_FILE));
            return self::SUCCESS;
        }

        // validate everything (policy file included) before the long scan & hash part
        $options = $this->readOptions($input);
        $style = new Style($input, $output, new FormatterHelper());

        $style->title('Check file duplications');
        $style->infoMessage('Reading directories.');

        $files = (new Scanner(
            $options['min-size'],
            (bool)$input->getOption('size'),
            (bool)$input->getOption('skip-empty'),
            $input->getOption('exclude'),
            $input->getOption('include'),
            (bool)$input->getOption('ignore-case')
        ))->scan($options['sources']);

        $style->infoMessage('Files to check: <info>' . \count($files) . '</>');

        if ($options['by-name'] !== null) {
            $hashes = (new Name())->group($files, $options['by-name']);
        } else {
            $style->infoMessage('Building file hash list.');
            $result = $options['hasher']->hash(
                $files,
                $options['chunk'],
                new ConsoleProgress($output, (bool)$input->getOption('progress-info'))
            );

            foreach ($result->errors as $error) {
                $style->errorMessage(OutputFormatter::escape($error));
            }

            $hashes = $result->hashes;
        }

        $groups = (new Grouper())->group($hashes);
        $duplicatedFiles = \array_sum(\array_map(static fn (DuplicateGroup $group): int => \count($group->files), $groups));
        $duplicatedSize = \array_sum(\array_map(static fn (DuplicateGroup $group): int => $group->size, $groups));

        $style->infoMessage('Duplications: <info>' . \count($groups) . '</>');

        if ($input->getOption('html') !== false) {
            $this->saveHtml($style, $groups, $input->getOption('html') ?? $this->defaultHtmlDir);
        }

        [$action, $deleter] = $this->action($input, $style, $options['policy']);

        foreach ($groups as $index => $group) {
            if ($input->getOption('interactive')) {
                $style->infoMessage('Duplication <options=bold>' . ($index + 1) . '</> of <info>' . \count($groups) . '</>');
            }

            $action->handle($group);
        }

        if ($deleter !== null) {
            $style->infoMessage('Deleted files: <info>' . $deleter->deletedFiles() . '</>');
            $style->infoMessage('Deleted files size: <info>' . Formats::dataSize($deleter->deletedSize()) . '</>');
        }

        $style->infoMessage("Duplicated files: <info>$duplicatedFiles</>");
        $style->infoMessage('Duplicated files size: <info>' . Formats::dataSize($duplicatedSize) . '</>');
        $style->newLine();

        return self::SUCCESS;
    }

    /**
     * @return array{sources: string[], min-size: int, chunk: int, by-name: ?int, hasher: Hasher, policy: DeletePolicy}
     * @throws \InvalidArgumentException
     */
    private function readOptions(InputInterface $input): array
    {
        foreach ([['interactive', 'list-only'], ['interactive', 'auto-delete']] as [$first, $second]) {
            if ($input->getOption($first) && $input->getOption($second)) {
                throw new \InvalidArgumentException("Options --$first and --$second are incompatible.");
            }
        }

        if (
            !$input->getOption('auto-delete')
            && (
                $input->getOption('delete-policy') !== null
                || $input->getOption('delete-backup') !== null
                || $input->getOption('auto-delete-test')
            )
        ) {
            throw new \InvalidArgumentException(
                'Options --delete-policy, --delete-backup and --auto-delete-test require --auto-delete.'
            );
        }

        if ($input->getOption('link') !== null && !$input->getOption('auto-delete') && !$input->getOption('interactive')) {
            throw new \InvalidArgumentException('Option --link requires --auto-delete or --interactive.');
        }

        if (!\in_array($input->getOption('link'), Deleter::LINKS, true)) {
            throw new \InvalidArgumentException('Option --link must be hard or soft.');
        }

        if ($input->getOption('keep-selected') && !$input->getOption('interactive')) {
            throw new \InvalidArgumentException('Option --keep-selected requires --interactive.');
        }

        $threads = $this->intOption($input, 'thread');
        $redis = $input->getOption('redis');

        if ($redis && $threads === 0) {
            throw new \InvalidArgumentException('Option --redis requires --thread greater than 0.');
        }

        $sources = $input->getArgument('source') ?: $this->defaultSources;

        if ($sources === []) {
            throw new \InvalidArgumentException('No source directory given.');
        }

        return [
            'sources' => $sources,
            'min-size' => $this->intOption($input, 'min-size'),
            'chunk' => $this->intOption($input, 'chunk'),
            'by-name' => $input->getOption('check-by-name') === null ? null : $this->intOption($input, 'check-by-name', 100),
            'hasher' => match (true) {
                $threads === 0 => new SingleProcess(),
                !$redis => new Threads($threads, new FileTransport()),
                default => new Threads($threads, RedisTransport::fromArray($this->redis)),
            },
            'policy' => $input->getOption('delete-policy') !== null
                ? DeletePolicy::fromFile($input->getOption('delete-policy'))
                : new DeletePolicy(),
        ];
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function intOption(InputInterface $input, string $name, int $max = PHP_INT_MAX): int
    {
        $value = $input->getOption($name);
        $int = \filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $max]]);

        if ($int === false) {
            throw new \InvalidArgumentException("Option --$name must be an integer between 0 and $max, got: $value");
        }

        return $int;
    }

    /**
     * @return array{0: Action, 1: ?Deleter}
     */
    private function action(InputInterface $input, Style $style, DeletePolicy $policy): array
    {
        if ($input->getOption('interactive')) {
            $deleter = new Deleter($style, link: $input->getOption('link'));
            $select = (new MultiSelect($style))->toggleShowInfo(false);

            return [new Interactive($style, $select, $deleter, (bool)$input->getOption('keep-selected')), $deleter];
        }

        if ($input->getOption('auto-delete')) {
            $deleter = new Deleter(
                $style,
                $input->getOption('delete-backup'),
                (bool)$input->getOption('auto-delete-test'),
                $input->getOption('link')
            );

            return [new AutoDelete($style, $policy, $deleter), $deleter];
        }

        return [new ListOnly($style, !$input->getOption('list-only')), null];
    }

    /**
     * @param DuplicateGroup[] $groups
     */
    private function saveHtml(Style $style, array $groups, string $dir): void
    {
        try {
            $pages = (new HtmlReport(self::HTML_PAGE_SIZE))->save($groups, $dir);
            $style->infoMessage("HTML report saved: <info>$dir/index.html</> ($pages pages)");
        } catch (\RuntimeException $exception) {
            $style->errorMessage($exception->getMessage());
        }
    }
}
