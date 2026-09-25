# duplicate-detector-lib Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `bluetree-service/duplicate-detector-lib`: a Composer library that finds duplicated files (single process, or N processes exchanging data through temp files or Redis) and lists them, writes an HTML report or deletes them (interactive / policy based), plus a ready Symfony Console command.

**Architecture:** Framework-free core (`Scanner` → `Hasher` → `Grouper` → `HtmlReport` / `DeletePolicy`) reporting progress through a small `Progress` interface. Multiprocessing is `Threads` + a `Transport` (`FileTransport` default, `RedisTransport` with `--redis`) and one worker script `bin/hash-worker.php`. Console layer (`Action/*`, `Deleter`, `Command/*`) uses `BlueConsole\Style`.

**Tech Stack:** PHP ^8.2, symfony/console ^7.4|^8.0, bluetree-service/symfony-console-style ^0.7, bluetree-service/data ^0.5, react/child-process ^0.6.6, PHPUnit ^11.5, optional ext-redis.

**Spec:** `docs/superpowers/specs/2026-09-25-duplicate-detector-lib-design.md`

**Repository:** `/Volumes/Linux/Dropbox/projects/blue/duplicate-detector-lib` (git, branch `main`, spec already committed). All paths below are relative to it. Reference sources: `../duplicate-detector/src` (auto delete, HTML), `../tools-cli/src/Tools/Fs` (Redis threads), `../class2/cache` (CI/Makefile/docker conventions).

## Global Constraints

- Package `bluetree-service/duplicate-detector-lib`, namespace `BlueDuplicateDetector\`, tests namespace `BlueDuplicateDetector\Test\`.
- PHP `^8.2`; every PHP file starts with `declare(strict_types=1);`.
- License Apache-2.0, author block as in `../class2/cache/composer.json`.
- Hash algorithm `sha3-256`; `chunk` = hash only first N bytes.
- `-t N` (N > 0) always runs N worker processes; `--redis[=host:port]` (default `127.0.0.1:6379`) only changes the transport.
- `ext-redis` is `suggest`, never `require`.
- No `bluetree-service/register`, `serafim/pipe`, `ramsey/uuid`.
- Temp data (session dir / Redis keys) is always removed, also on failure.
- Command name and default sources are constructor parameters (app: `duplicate`, `['/duplicates']`; tools-cli: `fs:duplicated`).
- Local test run: `composer test` (Redis tests skip without ext-redis/Redis). Full run: `make run-and-test php_version=84`.

## Deviations from spec (decided while planning, confirm at review)

1. `Progress` has `start/advance/finish` only; threads report as `advance("thread N: K")` (one bar instead of per-thread lines with cursor tricks).
2. Transports use `serialize()` instead of JSON: file names are not guaranteed UTF-8 and `json_encode` would fail on them. Worker stdout progress stays JSON lines.
3. Directory reading and delete/copy use stdlib (`RecursiveDirectoryIterator`, `unlink`, `copy`) — `bluetree-service/filesystem` is not needed.
4. No `Counters` class: `Deleter` counts deleted files/size, command computes duplicated files/size from groups.
5. Test fixtures are generated per test (`tests/Fixture.php`) instead of copying `tests/test-files`.
6. Group and file sorting (directory, then name) moves to `Grouper`, so console output, auto delete ("keep first") and HTML share one deterministic order.
7. `check-by-name` skips hashing in every mode (today it silently hashes in thread mode).
8. Behaviour fixes vs `AutoDel`: `*_datetime_gt` means *file time after the rule date* (old code was inverted); a failed backup no longer deletes the file; interactive selection of **all** copies in a group is refused.

## Review Focus

1. Overlapping sources (`/a` and `/a/b`, or the same dir twice) — a file must never be reported as a duplicate of itself (and then deleted). Test: Task 1.
2. Interactive delete — the file deleted is the one the user saw at that position, and selecting every copy deletes nothing. Test: Task 7.
3. Backup before delete fails, or backup destination resolves to the source file — the source must stay. Test: Task 7.
4. Worker process fails (crash, wrong PHP binary, missing result) — run fails with a clear exception, no partial result, temp data removed. Test: Task 3.
5. Unusual file names (spaces, quotes, `ż`, non-UTF-8 bytes, `<tag>`) — survive worker transport and are escaped in console and HTML output. Tests: Task 3, Task 7, Task 8.

---

### Task 1: Package scaffold, Progress, Scanner

**Files:**
- Create: `composer.json`, `phpunit.xml`, `.gitignore`, `LICENSE` (copy of `../class2/cache/LICENSE`)
- Create: `src/Progress/Progress.php`, `src/Progress/NullProgress.php`, `src/Scanner.php`
- Create: `tests/Fixture.php`, `tests/ScannerTest.php`

**Interfaces:**
- Produces: `Progress::start(int $max): void`, `Progress::advance(string $message = ''): void`, `Progress::finish(): void`; `NullProgress`; `Scanner::__construct(int $minSize = 0, bool $sameSizeOnly = false, bool $skipEmpty = false)`, `Scanner::scan(array $sources): array` (string[] paths, throws `InvalidArgumentException` for missing source); `Fixture::STANDARD`, `Fixture::create(array $files): string`, `Fixture::dir(): string`, `Fixture::remove(string $dir): void`, `Fixture::normalize(array $hashes): array`, `Fixture::style(BufferedOutput $output): Style`.

- [ ] **Step 1: Create `composer.json`**

```json
{
    "name": "bluetree-service/duplicate-detector-lib",
    "type": "library",
    "license": "Apache-2.0",
    "description": "Find duplicated files (single process or multiple processes, optionally through Redis) and list, report or delete them.",
    "keywords": ["duplicate", "files", "hash", "console"],
    "homepage": "https://github.com/bluetree-service/duplicate-detector-lib",
    "authors": [
        {
            "name": "Michał Adamiak",
            "email": "chajr@bluetree.pl",
            "homepage": "https://github.com/chajr",
            "role": "Developer"
        }
    ],
    "require": {
        "php": "^8.2",
        "ext-json": "*",
        "symfony/console": "^7.4|^8.0",
        "bluetree-service/symfony-console-style": "^0.7",
        "bluetree-service/data": "^0.5",
        "react/child-process": "^0.6.6",
        "react/event-loop": "^1.5"
    },
    "suggest": {
        "ext-redis": "Required for --redis transport between hashing processes"
    },
    "autoload": {
        "psr-4": {
            "BlueDuplicateDetector\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "BlueDuplicateDetector\\Test\\": "tests/"
        }
    },
    "require-dev": {
        "phpunit/phpunit": "^11.5",
        "roave/security-advisories": "dev-latest",
        "bluetree-service/clover-reporter": "^0.3.1"
    },
    "scripts": {
        "test": "./vendor/bin/phpunit --display-warnings",
        "coverage": "./vendor/bin/phpunit --display-warnings --coverage-clover build/logs/clover.xml",
        "clover-report": "./vendor/bin/clover_reporter -c build/logs/clover.xml",
        "clover-report-full": "./vendor/bin/clover_reporter -f build/logs/clover.xml",
        "test-complete": [
            "@coverage"
        ]
    }
}
```

- [ ] **Step 2: Create `phpunit.xml` and `.gitignore`, copy LICENSE, install**

`phpunit.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true" cacheDirectory=".phpunit.cache">
  <testsuites>
    <testsuite name="duplicate-detector-lib">
      <directory>tests</directory>
    </testsuite>
  </testsuites>
  <source>
    <include>
      <directory suffix=".php">src</directory>
    </include>
  </source>
</phpunit>
```

`.gitignore`:
```
vendor
build
.phpunit.result.cache
.phpunit.cache
.idea
.DS_Store
```

Run: `cp ../class2/cache/LICENSE . && composer install`
Expected: installs without errors, `vendor/autoload.php` exists.

- [ ] **Step 3: Create `tests/Fixture.php`**

```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test;

use BlueConsole\Style;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class Fixture
{
    /**
     * Groups by content: "same content" x3 (12 B), "other content" x2 (13 B), empty x2,
     * prefix-1/prefix-2 differ only after 7th byte (11 B each), unique.txt alone (7 B).
     */
    public const STANDARD = [
        'a/one.txt' => 'same content',
        'b/two.txt' => 'same content',
        'b/c/three.txt' => 'same content',
        'd/other.txt' => 'other content',
        "h/spaced name ż's.txt" => 'other content',
        'e/unique.txt' => 'unique!',
        'f/empty1' => '',
        'f/empty2' => '',
        'g/prefix-1.bin' => 'PREFIX-AAAA',
        'g/prefix-2.bin' => 'PREFIX-BBBB',
    ];

    public static function dir(): string
    {
        $dir = \sys_get_temp_dir() . '/dd-test-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0777, true);

        return $dir;
    }

    /**
     * @param array<string, string> $files relative path => content
     */
    public static function create(array $files): string
    {
        $dir = self::dir();

        foreach ($files as $path => $content) {
            if (!\is_dir(\dirname("$dir/$path"))) {
                \mkdir(\dirname("$dir/$path"), 0777, true);
            }

            \file_put_contents("$dir/$path", $content);
        }

        return $dir;
    }

    public static function remove(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        }

        \rmdir($dir);
    }

    /**
     * Order independent form of hash => files map, to compare results of different hashers.
     *
     * @param array<string, string[]> $hashes
     * @return array<string, string[]>
     */
    public static function normalize(array $hashes): array
    {
        foreach ($hashes as &$files) {
            \sort($files);
        }

        \ksort($hashes);

        return $hashes;
    }

    public static function style(BufferedOutput $output): Style
    {
        return new Style(new ArrayInput([]), $output);
    }
}
```

- [ ] **Step 4: Write failing `tests/ScannerTest.php`**

```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test;

use BlueDuplicateDetector\Scanner;
use PHPUnit\Framework\TestCase;

class ScannerTest extends TestCase
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

    public function testScanReturnsAllFilesRecursively(): void
    {
        $files = (new Scanner())->scan([$this->dir]);

        $this->assertCount(10, $files);
        $this->assertContains("$this->dir/b/c/three.txt", $files);
    }

    public function testAcceptsSingleFileAsSource(): void
    {
        $this->assertSame(["$this->dir/a/one.txt"], (new Scanner())->scan(["$this->dir/a/one.txt"]));
    }

    public function testOverlappingSourcesDoNotDuplicateFiles(): void
    {
        $files = (new Scanner())->scan([$this->dir, "$this->dir/b", $this->dir, "$this->dir/a/one.txt"]);

        $this->assertCount(10, $files);
    }

    public function testMinSizeFiltersSmallFiles(): void
    {
        $files = (new Scanner(minSize: 12))->scan([$this->dir]);

        $this->assertCount(5, $files);
    }

    public function testSkipEmpty(): void
    {
        $files = (new Scanner(skipEmpty: true))->scan([$this->dir]);

        $this->assertCount(8, $files);
        $this->assertNotContains("$this->dir/f/empty1", $files);
    }

    public function testSameSizeOnlyDropsFilesWithUniqueSize(): void
    {
        $files = (new Scanner(sameSizeOnly: true))->scan([$this->dir]);

        $this->assertCount(9, $files);
        $this->assertNotContains("$this->dir/e/unique.txt", $files);
    }

    public function testSameSizeOnlyAppliesAfterOtherFilters(): void
    {
        $dir = Fixture::create(['x/a' => '', 'x/b' => '', 'x/c' => '1']);

        try {
            $this->assertSame([], (new Scanner(sameSizeOnly: true, skipEmpty: true))->scan([$dir]));
        } finally {
            Fixture::remove($dir);
        }
    }

    public function testMissingSourceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Source not found');

        (new Scanner())->scan(["$this->dir/missing"]);
    }
}
```

- [ ] **Step 5: Run to verify failure**

Run: `composer test -- --filter ScannerTest`
Expected: FAIL, `Class "BlueDuplicateDetector\Scanner" not found`.

- [ ] **Step 6: Implement Progress and Scanner**

`src/Progress/Progress.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Progress;

interface Progress
{
    public function start(int $max): void;

    public function advance(string $message = ''): void;

    public function finish(): void;
}
```

`src/Progress/NullProgress.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Progress;

final class NullProgress implements Progress
{
    public function start(int $max): void
    {
    }

    public function advance(string $message = ''): void
    {
    }

    public function finish(): void
    {
    }
}
```

`src/Scanner.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector;

final class Scanner
{
    public function __construct(
        private readonly int $minSize = 0,
        private readonly bool $sameSizeOnly = false,
        private readonly bool $skipEmpty = false,
    ) {
    }

    /**
     * @param string[] $sources directories (read recursively) or files
     * @return string[]
     * @throws \InvalidArgumentException
     */
    public function scan(array $sources): array
    {
        $unique = [];

        foreach ($sources as $source) {
            foreach ($this->read($source) as $file) {
                // overlapping sources must not make a file its own duplicate
                $unique[\realpath($file) ?: $file] ??= $file;
            }
        }

        $files = [];
        $sizes = [];

        foreach ($unique as $file) {
            $size = (int)@\filesize($file);

            if ($size < $this->minSize || ($this->skipEmpty && $size === 0)) {
                continue;
            }

            $files[] = $file;
            $sizes[] = $size;
        }

        if ($this->sameSizeOnly) {
            $counts = \array_count_values($sizes);
            $files = \array_values(\array_filter(
                $files,
                static fn (int $index): bool => $counts[$sizes[$index]] > 1,
                ARRAY_FILTER_USE_KEY
            ));
        }

        return $files;
    }

    /**
     * @return iterable<string>
     */
    private function read(string $source): iterable
    {
        if (\is_file($source)) {
            return [$source];
        }

        if (!\is_dir($source)) {
            throw new \InvalidArgumentException("Source not found: $source");
        }

        $directories = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $item): bool => !$item->isDir() || $item->isReadable()
        );

        $files = [];

        foreach (new \RecursiveIteratorIterator($directories) as $item) {
            if ($item->isFile()) {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }
}
```

- [ ] **Step 7: Run tests**

Run: `composer test -- --filter ScannerTest`
Expected: PASS (8 tests).

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock phpunit.xml .gitignore LICENSE src tests
git commit -m "Added package scaffold and file scanner"
```

---

### Task 2: HashResult, FileHash, SingleProcess hasher

**Files:**
- Create: `src/Hasher/Hasher.php`, `src/Hasher/HashResult.php`, `src/Hasher/FileHash.php`, `src/Hasher/SingleProcess.php`
- Test: `tests/Hasher/SingleProcessTest.php`, `tests/Hasher/HashResultTest.php`

**Interfaces:**
- Consumes: `Progress`, `Fixture` (Task 1).
- Produces: `Hasher::hash(array $files, int $chunk, Progress $progress): HashResult`; `HashResult` with `public readonly array $hashes` (hash => string[]), `public readonly array $errors` (string[]), `merge(HashResult $other): HashResult`; `FileHash::of(string $file, int $chunk = 0): string` (throws `RuntimeException`), `FileHash::hashList(iterable $files, int $chunk, callable $afterFile): HashResult` where `$afterFile(string $file, int $done): void`; `SingleProcess`.

- [ ] **Step 1: Write failing tests**

`tests/Hasher/HashResultTest.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test\Hasher;

use BlueDuplicateDetector\Hasher\HashResult;
use PHPUnit\Framework\TestCase;

class HashResultTest extends TestCase
{
    public function testMergeJoinsFilesOfSameHashAndErrors(): void
    {
        $first = new HashResult(['aa' => ['/1'], 'bb' => ['/2']], ['e1']);
        $second = new HashResult(['aa' => ['/3'], '123' => ['/4']], ['e2']);

        $merged = $first->merge($second);

        $this->assertSame(['/1', '/3'], $merged->hashes['aa']);
        $this->assertSame(['/2'], $merged->hashes['bb']);
        $this->assertSame(['/4'], $merged->hashes['123']);
        $this->assertSame(['e1', 'e2'], $merged->errors);
    }
}
```

`tests/Hasher/SingleProcessTest.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test\Hasher;

use BlueDuplicateDetector\Hasher\SingleProcess;
use BlueDuplicateDetector\Progress\NullProgress;
use BlueDuplicateDetector\Progress\Progress;
use BlueDuplicateDetector\Scanner;
use BlueDuplicateDetector\Test\Fixture;
use PHPUnit\Framework\TestCase;

class SingleProcessTest extends TestCase
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

    public function testGroupsFilesByContentHash(): void
    {
        $result = (new SingleProcess())->hash((new Scanner())->scan([$this->dir]), 0, new NullProgress());

        $this->assertCount(6, $result->hashes);
        $this->assertSame([], $result->errors);
        $this->assertCount(3, $result->hashes[\hash('sha3-256', 'same content')]);
    }

    public function testChunkHashesOnlyFileBeginning(): void
    {
        $result = (new SingleProcess())->hash((new Scanner())->scan([$this->dir]), 7, new NullProgress());

        $this->assertCount(5, $result->hashes);
        $this->assertCount(2, $result->hashes[\hash('sha3-256', 'PREFIX-')]);
    }

    public function testUnreadableFileIsReportedAndSkipped(): void
    {
        $result = (new SingleProcess())->hash(["$this->dir/a/one.txt", "$this->dir/missing"], 0, new NullProgress());

        $this->assertCount(1, $result->hashes);
        $this->assertSame(["Unable to read file: $this->dir/missing"], $result->errors);
    }

    public function testReportsProgressPerFile(): void
    {
        $progress = $this->createMock(Progress::class);
        $progress->expects($this->once())->method('start')->with(2);
        $progress->expects($this->exactly(2))->method('advance');
        $progress->expects($this->once())->method('finish');

        (new SingleProcess())->hash(["$this->dir/a/one.txt", "$this->dir/b/two.txt"], 0, $progress);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter 'SingleProcessTest|HashResultTest'`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement**

`src/Hasher/Hasher.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

use BlueDuplicateDetector\Progress\Progress;

interface Hasher
{
    /**
     * @param string[] $files
     * @param int $chunk hash only first $chunk bytes, 0 = whole file
     */
    public function hash(array $files, int $chunk, Progress $progress): HashResult;
}
```

`src/Hasher/HashResult.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

final class HashResult
{
    /**
     * @param array<string, string[]> $hashes hash => files
     * @param string[] $errors
     */
    public function __construct(
        public readonly array $hashes = [],
        public readonly array $errors = [],
    ) {
    }

    public function merge(HashResult $other): self
    {
        $hashes = $this->hashes;

        // no array_merge_recursive: numeric-looking hash keys would be renumbered
        foreach ($other->hashes as $hash => $files) {
            $hashes[$hash] = [...($hashes[$hash] ?? []), ...$files];
        }

        return new self($hashes, [...$this->errors, ...$other->errors]);
    }
}
```

`src/Hasher/FileHash.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

final class FileHash
{
    public const ALGORITHM = 'sha3-256';

    /**
     * @throws \RuntimeException
     */
    public static function of(string $file, int $chunk = 0): string
    {
        if ($chunk > 0) {
            $content = @\file_get_contents($file, false, null, 0, $chunk);
            $hash = $content === false ? false : \hash(self::ALGORITHM, $content);
        } else {
            $hash = @\hash_file(self::ALGORITHM, $file);
        }

        if ($hash === false) {
            throw new \RuntimeException("Unable to read file: $file");
        }

        return $hash;
    }

    /**
     * Shared by SingleProcess and bin/hash-worker.php.
     *
     * @param iterable<string> $files
     * @param callable(string, int): void $afterFile called with file and number of processed files
     */
    public static function hashList(iterable $files, int $chunk, callable $afterFile): HashResult
    {
        $hashes = [];
        $errors = [];
        $done = 0;

        foreach ($files as $file) {
            try {
                $hashes[self::of($file, $chunk)][] = $file;
            } catch (\RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }

            $afterFile($file, ++$done);
        }

        return new HashResult($hashes, $errors);
    }
}
```

`src/Hasher/SingleProcess.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

use BlueDuplicateDetector\Progress\Progress;

final class SingleProcess implements Hasher
{
    public function hash(array $files, int $chunk, Progress $progress): HashResult
    {
        $progress->start(\count($files));

        try {
            return FileHash::hashList(
                $files,
                $chunk,
                static fn (string $file) => $progress->advance($file)
            );
        } finally {
            $progress->finish();
        }
    }
}
```

- [ ] **Step 4: Run tests**

Run: `composer test`
Expected: PASS (all tests so far).

- [ ] **Step 5: Commit**

```bash
git add src/Hasher tests/Hasher
git commit -m "Added single process file hasher"
```

---

### Task 3: Worker script, FileTransport, Threads

**Files:**
- Create: `bin/hash-worker.php`, `src/Hasher/Transport.php`, `src/Hasher/FileTransport.php`, `src/Hasher/Threads.php`
- Test: `tests/Hasher/ThreadsTest.php`

**Interfaces:**
- Consumes: `FileHash::hashList`, `HashResult`, `Hasher`, `Progress`, `Fixture`.
- Produces: `Transport` (`open(array $files, int $threads): void`, `workerArgs(int $thread, int $chunk): array`, `collect(int $threads): HashResult`, `close(): void`); `FileTransport::__construct(string $tmpDir = '')`; `Threads::__construct(int $threads, Transport $transport, string $php = PHP_BINARY)`; worker CLI `hash-worker.php file <in> <out> <chunk> <thread>` / `hash-worker.php redis <host> <port> <session> <chunk> <thread>`, progress lines `{"thread":N,"done":K}\n`, exit code 1 + message on stderr on failure.

- [ ] **Step 1: Write failing `tests/Hasher/ThreadsTest.php`**

```php
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
        $progress->expects($this->once())->method('start')->with(10);
        $progress->expects($this->exactly(10))->method('advance');
        $progress->expects($this->once())->method('finish');

        (new Threads(3, new FileTransport($this->tmp)))->hash((new Scanner())->scan([$this->dir]), 0, $progress);
    }

    public function testThreadsMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Threads(0, new FileTransport());
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter ThreadsTest`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement Transport and FileTransport**

`src/Hasher/Transport.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

/**
 * How file lists reach worker processes and how their results come back.
 */
interface Transport
{
    /**
     * @param string[] $files
     * @throws \RuntimeException
     */
    public function open(array $files, int $threads): void;

    /**
     * @return string[] arguments passed to bin/hash-worker.php
     */
    public function workerArgs(int $thread, int $chunk): array;

    /**
     * @throws \RuntimeException when result of any thread is missing
     */
    public function collect(int $threads): HashResult;

    /**
     * Remove everything open() created. Safe to call more than once.
     */
    public function close(): void;
}
```

`src/Hasher/FileTransport.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

/**
 * File list split into chunks, one serialized input and output file per worker.
 * serialize() instead of JSON, file names don't have to be valid UTF-8.
 */
final class FileTransport implements Transport
{
    private ?string $dir = null;

    public function __construct(private readonly string $tmpDir = '')
    {
    }

    public function open(array $files, int $threads): void
    {
        $this->dir = \rtrim($this->tmpDir ?: \sys_get_temp_dir(), '/') . '/dup-' . \bin2hex(\random_bytes(8));

        if (!@\mkdir($this->dir, 0700, true)) {
            throw new \RuntimeException("Unable to create temporary directory: $this->dir");
        }

        $chunks = \array_chunk($files, \max(1, (int)\ceil(\count($files) / $threads)));

        for ($thread = 0; $thread < $threads; $thread++) {
            if (\file_put_contents($this->input($thread), \serialize($chunks[$thread] ?? [])) === false) {
                throw new \RuntimeException("Unable to write file list: {$this->input($thread)}");
            }
        }
    }

    public function workerArgs(int $thread, int $chunk): array
    {
        return ['file', $this->input($thread), $this->output($thread), (string)$chunk, (string)$thread];
    }

    public function collect(int $threads): HashResult
    {
        $result = new HashResult();

        for ($thread = 0; $thread < $threads; $thread++) {
            $content = @\file_get_contents($this->output($thread));
            $data = $content === false ? false : \unserialize($content, ['allowed_classes' => false]);

            if (!\is_array($data)) {
                throw new \RuntimeException("Missing result of thread $thread");
            }

            $result = $result->merge(new HashResult($data['hashes'], $data['errors']));
        }

        return $result;
    }

    public function close(): void
    {
        if ($this->dir === null) {
            return;
        }

        foreach (\glob("$this->dir/*") ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($this->dir);
        $this->dir = null;
    }

    private function input(int $thread): string
    {
        return "$this->dir/in-$thread.ser";
    }

    private function output(int $thread): string
    {
        return "$this->dir/out-$thread.ser";
    }
}
```

- [ ] **Step 4: Implement worker `bin/hash-worker.php`**

```php
<?php

declare(strict_types=1);

/**
 * Hashing worker started by BlueDuplicateDetector\Hasher\Threads.
 *
 * hash-worker.php file <input> <output> <chunk> <thread>
 * hash-worker.php redis <host> <port> <session> <chunk> <thread>
 *
 * stdout: one JSON line per processed file {"thread":N,"done":K}
 * on failure: message on stderr, exit code 1
 */

use BlueDuplicateDetector\Hasher\FileHash;

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $autoload) {
    if (\is_file($autoload)) {
        require $autoload;
        break;
    }
}

function progress(int $thread): \Closure
{
    return static function (string $file, int $done) use ($thread): void {
        echo \json_encode(['thread' => $thread, 'done' => $done]), "\n";
    };
}

try {
    $mode = $argv[1] ?? '';

    if ($mode === 'file') {
        [, , $input, $output, $chunk, $thread] = $argv;

        $content = \file_get_contents($input);
        $files = $content === false ? false : \unserialize($content, ['allowed_classes' => false]);

        if (!\is_array($files)) {
            throw new \RuntimeException("Invalid file list: $input");
        }

        $result = FileHash::hashList($files, (int)$chunk, progress((int)$thread));
        $data = \serialize(['hashes' => $result->hashes, 'errors' => $result->errors]);

        if (\file_put_contents($output, $data) === false) {
            throw new \RuntimeException("Unable to write result: $output");
        }
    } elseif ($mode === 'redis') {
        [, , $host, $port, $session, $chunk, $thread] = $argv;

        $redis = new \Redis();
        $redis->connect($host, (int)$port, 2.0);

        $queue = (static function () use ($redis, $session): \Generator {
            while (\is_string($file = $redis->lPop("$session-paths"))) {
                yield $file;
            }
        })();

        $result = FileHash::hashList($queue, (int)$chunk, progress((int)$thread));
        $redis->hSet("$session-hashes", "thread-$thread", \serialize($result->hashes));

        if ($result->errors !== []) {
            $redis->sAdd("$session-errors", ...$result->errors);
        }
    } else {
        throw new \InvalidArgumentException("Unknown mode: $mode");
    }
} catch (\Throwable $exception) {
    \fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
```

- [ ] **Step 5: Implement `src/Hasher/Threads.php`**

```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

use BlueDuplicateDetector\Progress\Progress;
use React\ChildProcess\Process;
use React\EventLoop\Loop;

/**
 * Always N worker processes, Transport decides how data is exchanged (files or Redis).
 */
final class Threads implements Hasher
{
    public const WORKER = __DIR__ . '/../../bin/hash-worker.php';

    public function __construct(
        private readonly int $threads,
        private readonly Transport $transport,
        private readonly string $php = PHP_BINARY,
    ) {
        if ($threads < 1) {
            throw new \InvalidArgumentException('Number of threads must be greater than 0.');
        }
    }

    public function hash(array $files, int $chunk, Progress $progress): HashResult
    {
        if ($files === []) {
            return new HashResult();
        }

        $progress->start(\count($files));

        try {
            $this->transport->open($files, $this->threads);
            $failed = $this->run($chunk, $progress);

            if ($failed !== []) {
                throw new \RuntimeException('Hash worker failed: ' . \implode('; ', $failed));
            }

            return $this->transport->collect($this->threads);
        } finally {
            $this->transport->close();
            $progress->finish();
        }
    }

    /**
     * @return string[] failure descriptions, empty when all workers succeeded
     */
    private function run(int $chunk, Progress $progress): array
    {
        $loop = Loop::get();
        $buffers = [];
        $stderr = [];
        $failed = [];

        for ($thread = 0; $thread < $this->threads; $thread++) {
            $buffers[$thread] = '';
            $stderr[$thread] = '';
            $args = [$this->php, self::WORKER, ...$this->transport->workerArgs($thread, $chunk)];

            $process = new Process(\implode(' ', \array_map('escapeshellarg', $args)));
            $process->start($loop);

            // stdout chunks may split or join lines, so buffer until "\n"
            $process->stdout->on('data', static function (string $data) use ($thread, &$buffers, $progress): void {
                $buffers[$thread] .= $data;

                while (($end = \strpos($buffers[$thread], "\n")) !== false) {
                    $line = \json_decode(\substr($buffers[$thread], 0, $end), true);
                    $buffers[$thread] = \substr($buffers[$thread], $end + 1);

                    if (isset($line['thread'], $line['done'])) {
                        $progress->advance("thread {$line['thread']}: {$line['done']}");
                    }
                }
            });

            $process->stderr->on('data', static function (string $data) use ($thread, &$stderr): void {
                $stderr[$thread] .= $data;
            });

            $process->on('exit', static function (?int $code) use ($thread, &$stderr, &$failed): void {
                if ($code !== 0) {
                    $message = \trim($stderr[$thread]);
                    $failed[] = "thread $thread exit code " . ($code ?? 'none') . ($message !== '' ? ": $message" : '');
                }
            });
        }

        $loop->run();

        return $failed;
    }
}
```

- [ ] **Step 6: Run tests**

Run: `composer test -- --filter ThreadsTest`
Expected: PASS (non-UTF-8 test may be skipped on macOS APFS).

- [ ] **Step 7: Commit**

```bash
git add bin src/Hasher tests/Hasher
git commit -m "Added multi process hashing with file transport"
```

---

### Task 4: RedisTransport + docker test environment

**Files:**
- Create: `src/Hasher/RedisTransport.php`, `tests/Hasher/RedisTransportTest.php`
- Create: `Makefile` (copy of `../class2/cache/Makefile`, unchanged), `tests/docker-compose82.yaml`, `tests/docker-compose83.yaml`, `tests/docker-compose84.yaml`, `tests/docker-compose85.yaml`
- Create: `.github/workflows/build.yml` (copy of `../class2/cache/.github/workflows/build.yml`), `.github/actions/test/action.yaml` (copy of `../class2/cache/.github/actions/test/action.yaml`)

**Interfaces:**
- Consumes: `Transport`, `HashResult`, `Threads`, worker `redis` mode (Task 3).
- Produces: `RedisTransport::__construct(string $host = '127.0.0.1', int $port = 6379)`, `RedisTransport::fromDsn(?string $dsn): self` (throws `InvalidArgumentException`), `RedisTransport::session(): string`. Redis keys: `{session}-paths` (list), `{session}-hashes` (hash, field `thread-N`, serialized), `{session}-errors` (set).

- [ ] **Step 1: Docker compose files**

`tests/docker-compose84.yaml` (82/83/85: replace every `84` with the version):
```yaml
services:
  php84:
    image: chajr/php84-dev
    depends_on:
      - redis84
    networks:
      - backend84
    command: ${RUN_COMMAND}
    environment:
      - REDIS_HOST=redis84
    volumes:
      - ${MOUNT_POINT}

  redis84:
    image: redis:latest
    networks:
      - backend84

networks:
  backend84:
    driver: bridge
```

Run: `cp ../class2/cache/Makefile . && mkdir -p .github/workflows .github/actions/test && cp ../class2/cache/.github/workflows/build.yml .github/workflows/ && cp ../class2/cache/.github/actions/test/action.yaml .github/actions/test/`

In `.github/workflows/build.yml` change the push branch from `master` to `main`.

- [ ] **Step 2: Write failing `tests/Hasher/RedisTransportTest.php`**

```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test\Hasher;

use BlueDuplicateDetector\Hasher\RedisTransport;
use BlueDuplicateDetector\Hasher\SingleProcess;
use BlueDuplicateDetector\Hasher\Threads;
use BlueDuplicateDetector\Progress\NullProgress;
use BlueDuplicateDetector\Scanner;
use BlueDuplicateDetector\Test\Fixture;
use PHPUnit\Framework\TestCase;

class RedisTransportTest extends TestCase
{
    private string $host;

    protected function setUp(): void
    {
        $this->host = \getenv('REDIS_HOST') ?: '127.0.0.1';
    }

    private function requireRedis(): \Redis
    {
        if (!\extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not loaded.');
        }

        try {
            $redis = new \Redis();
            $redis->connect($this->host, 6379, 1.0);

            return $redis;
        } catch (\RedisException) {
            $this->markTestSkipped("Redis not reachable on $this->host:6379.");
        }
    }

    public function testFromDsn(): void
    {
        $this->assertEquals(new RedisTransport(), RedisTransport::fromDsn(null));
        $this->assertEquals(new RedisTransport('redis'), RedisTransport::fromDsn('redis'));
        $this->assertEquals(new RedisTransport('10.0.0.1', 6378), RedisTransport::fromDsn('10.0.0.1:6378'));
    }

    public function testInvalidDsnThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RedisTransport::fromDsn('host:port:x');
    }

    public function testMissingExtensionThrows(): void
    {
        if (\extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis loaded.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ext-redis');

        (new RedisTransport())->open(['/x'], 1);
    }

    public function testUnreachableRedisThrows(): void
    {
        if (!\extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not loaded.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to connect to Redis');

        (new RedisTransport('127.0.0.1', 1))->open(['/x'], 1);
    }

    public function testSameResultAsSingleProcessAndKeysRemoved(): void
    {
        $redis = $this->requireRedis();
        $dir = Fixture::create(Fixture::STANDARD);

        try {
            $files = [...(new Scanner())->scan([$dir]), "$dir/missing"];
            $transport = new RedisTransport($this->host);

            $expected = (new SingleProcess())->hash($files, 0, new NullProgress());
            $actual = (new Threads(3, $transport))->hash($files, 0, new NullProgress());

            $this->assertSame(Fixture::normalize($expected->hashes), Fixture::normalize($actual->hashes));
            $this->assertSame($expected->errors, $actual->errors);

            $session = $transport->session();
            $this->assertSame(0, $redis->exists("$session-paths", "$session-hashes", "$session-errors"));
        } finally {
            Fixture::remove($dir);
        }
    }
}
```

- [ ] **Step 3: Run to verify failure**

Run: `composer test -- --filter RedisTransportTest`
Expected: FAIL, `Class "BlueDuplicateDetector\Hasher\RedisTransport" not found`.

- [ ] **Step 4: Implement `src/Hasher/RedisTransport.php`**

```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Hasher;

/**
 * Shared Redis queue: workers pop paths until the list is empty, so fast threads take more files.
 */
final class RedisTransport implements Transport
{
    private ?\Redis $redis = null;
    private string $session = '';

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
    ) {
    }

    /**
     * @param string|null $dsn host[:port], null or empty = defaults
     * @throws \InvalidArgumentException
     */
    public static function fromDsn(?string $dsn): self
    {
        if ($dsn === null || $dsn === '') {
            return new self();
        }

        if (!\preg_match('/^([^:]+)(?::(\d+))?$/', $dsn, $match)) {
            throw new \InvalidArgumentException("Invalid Redis address: $dsn (expected host[:port])");
        }

        return new self($match[1], isset($match[2]) ? (int)$match[2] : 6379);
    }

    public function session(): string
    {
        return $this->session;
    }

    public function open(array $files, int $threads): void
    {
        if (!\extension_loaded('redis')) {
            throw new \RuntimeException('Redis transport requires ext-redis.');
        }

        $redis = new \Redis();

        try {
            if (!$redis->connect($this->host, $this->port, 2.0)) {
                throw new \RedisException('connection refused');
            }
        } catch (\RedisException $exception) {
            throw new \RuntimeException(
                "Unable to connect to Redis $this->host:$this->port: {$exception->getMessage()}",
                0,
                $exception
            );
        }

        $this->redis = $redis;
        $this->session = 'dup-' . \bin2hex(\random_bytes(8));

        foreach (\array_chunk($files, 1000) as $part) {
            $this->redis->rPush("$this->session-paths", ...$part);
        }
    }

    public function workerArgs(int $thread, int $chunk): array
    {
        return ['redis', $this->host, (string)$this->port, $this->session, (string)$chunk, (string)$thread];
    }

    public function collect(int $threads): HashResult
    {
        $result = new HashResult([], $this->redis->sMembers("$this->session-errors") ?: []);

        for ($thread = 0; $thread < $threads; $thread++) {
            $data = $this->redis->hGet("$this->session-hashes", "thread-$thread");
            $hashes = \is_string($data) ? \unserialize($data, ['allowed_classes' => false]) : false;

            if (!\is_array($hashes)) {
                throw new \RuntimeException("Missing result of thread $thread");
            }

            $result = $result->merge(new HashResult($hashes));
        }

        return $result;
    }

    public function close(): void
    {
        if ($this->redis === null) {
            return;
        }

        $this->redis->del("$this->session-paths", "$this->session-hashes", "$this->session-errors");
        $this->redis->close();
        $this->redis = null;
    }
}
```

- [ ] **Step 5: Run tests locally and in docker**

Run: `composer test`
Expected: PASS, Redis-dependent tests skipped locally when ext-redis is missing.

Run: `make run-and-test php_version=84`
Expected: PASS inside container, `testSameResultAsSingleProcessAndKeysRemoved` executed (not skipped). If `chajr/php84-dev` lacks ext-redis, report it instead of changing the image.

- [ ] **Step 6: Commit**

```bash
git add src/Hasher/RedisTransport.php tests/Hasher/RedisTransportTest.php Makefile tests/docker-compose*.yaml .github
git commit -m "Added Redis transport for hashing processes and docker test environment"
```

---

### Task 5: DuplicateGroup, Grouper, Name

**Files:**
- Create: `src/DuplicateGroup.php`, `src/Grouper.php`, `src/Name.php`
- Test: `tests/GrouperTest.php`, `tests/NameTest.php`

**Interfaces:**
- Consumes: `Fixture`.
- Produces: `DuplicateGroup` (`public readonly string $key`, `public readonly array $files` string[] sorted, `public readonly array $sizes` path => int, `public readonly int $size`); `Grouper::group(array $hashes): DuplicateGroup[]` (sorted, only groups with ≥ 2 unique files); `Grouper::compareFiles(string $first, string $second): int`; `Name::group(array $files, int $similarity): array` (name => string[]).

- [ ] **Step 1: Write failing tests**

`tests/GrouperTest.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test;

use BlueDuplicateDetector\Grouper;
use PHPUnit\Framework\TestCase;

class GrouperTest extends TestCase
{
    public function testDropsSingleFilesAndDuplicatedPaths(): void
    {
        $groups = (new Grouper())->group([
            'h1' => ['/x/a', '/x/a'],
            'h2' => ['/x/b'],
            'h3' => ['/x/c', '/y/c'],
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame('h3', $groups[0]->key);
        $this->assertSame(['/x/c', '/y/c'], $groups[0]->files);
    }

    public function testSortsFilesByDirectoryThenNameAndGroupsByDirectories(): void
    {
        $groups = (new Grouper())->group([
            'h1' => ['/b/file10', '/a/z', '/b/file2'],
            'h2' => ['/a/y', '/A/x'],
            '123' => ['/c/1', '/c/2'],
        ]);

        $this->assertSame(['/a/z', '/b/file2', '/b/file10'], $groups[1]->files);
        $this->assertSame(['/A/x', '/a/y'], $groups[0]->files);
        $this->assertSame('123', $groups[2]->key);
    }

    public function testComputesSizes(): void
    {
        $dir = Fixture::create(['a' => '12345', 'b' => '12345']);

        try {
            $group = (new Grouper())->group(['h' => ["$dir/a", "$dir/b"]])[0];

            $this->assertSame(["$dir/a" => 5, "$dir/b" => 5], $group->sizes);
            $this->assertSame(10, $group->size);
        } finally {
            Fixture::remove($dir);
        }
    }
}
```

`tests/NameTest.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test;

use BlueDuplicateDetector\Name;
use PHPUnit\Framework\TestCase;

class NameTest extends TestCase
{
    public function testGroupsSimilarNames(): void
    {
        $groups = (new Name())->group(['/a/photo-001.jpg', '/b/photo-002.jpg', '/c/report.pdf'], 90);

        $this->assertSame(['photo-001.jpg' => ['/a/photo-001.jpg', '/b/photo-002.jpg']], $groups);
    }

    public function testSameNameInManyDirectories(): void
    {
        $groups = (new Name())->group(['/a/file', '/b/file', '/c/file'], 100);

        $this->assertSame(['/a/file', '/b/file', '/c/file'], \array_values(\array_unique($groups['file'])));
    }

    public function testNoMatch(): void
    {
        $this->assertSame([], (new Name())->group(['/a/abc', '/b/xyz'], 50));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter 'GrouperTest|NameTest'`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement**

`src/DuplicateGroup.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector;

final class DuplicateGroup
{
    /**
     * @var array<string, int> path => size in bytes (0 when unreadable)
     */
    public readonly array $sizes;

    public readonly int $size;

    /**
     * @param string $key hash or file name (check by name)
     * @param string[] $files
     */
    public function __construct(
        public readonly string $key,
        public readonly array $files,
    ) {
        $sizes = [];

        foreach ($files as $file) {
            $sizes[$file] = (int)@\filesize($file);
        }

        $this->sizes = $sizes;
        $this->size = \array_sum($sizes);
    }
}
```

`src/Grouper.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector;

final class Grouper
{
    /**
     * Files in group sorted by directory then name, groups sorted by their directory lists.
     *
     * @param array<string|int, string[]> $hashes hash => files
     * @return DuplicateGroup[]
     */
    public function group(array $hashes): array
    {
        $groups = [];

        foreach ($hashes as $key => $files) {
            $files = \array_values(\array_unique($files));

            if (\count($files) < 2) {
                continue;
            }

            \usort($files, [self::class, 'compareFiles']);
            $groups[] = new DuplicateGroup((string)$key, $files);
        }

        // compare directory lists: first directories, on tie second ones etc., then file names
        \usort($groups, static function (DuplicateGroup $first, DuplicateGroup $second): int {
            $count = \min(\count($first->files), \count($second->files));

            for ($i = 0; $i < $count; $i++) {
                $result = \strnatcasecmp(\dirname($first->files[$i]), \dirname($second->files[$i]));

                if ($result !== 0) {
                    return $result;
                }
            }

            return \count($first->files) <=> \count($second->files)
                ?: self::compareFiles($first->files[0], $second->files[0]);
        });

        return $groups;
    }

    public static function compareFiles(string $first, string $second): int
    {
        return \strnatcasecmp(\dirname($first), \dirname($second))
            ?: \strnatcasecmp(\basename($first), \basename($second));
    }
}
```

Note: `strnatcasecmp('/A', '/a')` is 0, so `['/A/x', '/a/y']` is ordered by file name — matches the test.

`src/Name.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector;

final class Name
{
    /**
     * @param string[] $files
     * @param int $similarity minimal similar_text() percent (0-100)
     * @return array<string, string[]> file name => paths with similar names
     */
    public function group(array $files, int $similarity): array
    {
        // ponytail: O(n²) similar_text over all pairs, fine for thousands of files, bucket by name prefix if slow
        $names = [];

        foreach ($files as $file) {
            $names[$file] = \basename($file);
        }

        $groups = [];

        foreach ($names as $path => $name) {
            unset($names[$path]);

            foreach ($names as $otherPath => $otherName) {
                \similar_text($name, $otherName, $percent);

                if ($percent >= $similarity) {
                    $groups[$name] ??= [$path];
                    $groups[$name][] = $otherPath;
                }
            }
        }

        return $groups;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `composer test -- --filter 'GrouperTest|NameTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/DuplicateGroup.php src/Grouper.php src/Name.php tests/GrouperTest.php tests/NameTest.php
git commit -m "Added duplicate grouping and check by name"
```

---

### Task 6: DeletePolicy

**Files:**
- Create: `src/Action/DeletePolicy.php`, `etc/delete_policy.json` (copy of `../duplicate-detector/etc/delete_policy.json`)
- Test: `tests/Action/DeletePolicyTest.php`

**Interfaces:**
- Consumes: `Fixture`.
- Produces: `DeletePolicy::__construct(array $keepRules = [], array $deleteRules = [])` (throws `InvalidArgumentException`), `DeletePolicy::fromFile(string $path): self`, `DeletePolicy::decide(array $files): array{keep: string[], delete: string[]}`.

Rules (both `keep_rule` and `delete_rule`, empty value = disabled, a file matches a rule set when it matches **any** rule): `filename_is` / `filename_not_is` (regex on basename matches / doesn't), `path_is` / `path_not_is` (regex on directory), `{a,c,m}_datetime_gt` (file time after `strtotime(rule)`), `{a,c,m}_datetime_lt` (before), `permissions` (`"644"` or `"0644"`), `owner` / `group` (lists of uid / gid).

Decision: keep = files matching keep rules. If none: keep first file, delete all others (delete rules ignored, as in current `AutoDel`). Otherwise: remaining files matching delete rules are deleted (all remaining when there are no delete rules); the rest is untouched.

- [ ] **Step 1: Write failing `tests/Action/DeletePolicyTest.php`**

```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Test\Action;

use BlueDuplicateDetector\Action\DeletePolicy;
use BlueDuplicateDetector\Test\Fixture;
use PHPUnit\Framework\TestCase;

class DeletePolicyTest extends TestCase
{
    private string $dir;
    private array $files;

    protected function setUp(): void
    {
        $this->dir = Fixture::create(Fixture::STANDARD);
        $this->files = ["$this->dir/a/one.txt", "$this->dir/b/two.txt", "$this->dir/b/c/three.txt"];
    }

    protected function tearDown(): void
    {
        Fixture::remove($this->dir);
    }

    public function testWithoutRulesKeepsFirst(): void
    {
        $this->assertSame(
            ['keep' => [$this->files[0]], 'delete' => [$this->files[1], $this->files[2]]],
            (new DeletePolicy())->decide($this->files)
        );
    }

    public function testKeepRuleWithoutDeleteRulesDeletesRest(): void
    {
        $decision = (new DeletePolicy(['path_is' => '#/b$#']))->decide($this->files);

        $this->assertSame([$this->files[1]], $decision['keep']);
        $this->assertSame([$this->files[0], $this->files[2]], $decision['delete']);
    }

    public function testDeleteRulesLimitDeletedFiles(): void
    {
        $decision = (new DeletePolicy(['path_is' => '#/b$#'], ['filename_is' => '/three/']))->decide($this->files);

        $this->assertSame([$this->files[1]], $decision['keep']);
        $this->assertSame([$this->files[2]], $decision['delete'], 'a/one.txt untouched');
    }

    public function testDeleteRulesIgnoredWhenNoKeepRuleMatches(): void
    {
        $decision = (new DeletePolicy(['path_is' => '#/none$#'], ['filename_is' => '/three/']))->decide($this->files);

        $this->assertSame([$this->files[0]], $decision['keep']);
        $this->assertSame([$this->files[1], $this->files[2]], $decision['delete']);
    }

    public function testNotRules(): void
    {
        $decision = (new DeletePolicy(['filename_not_is' => '/two|three/']))->decide($this->files);

        $this->assertSame([$this->files[0]], $decision['keep']);
    }

    public function testDateRules(): void
    {
        \touch($this->files[2], \strtotime('2000-06-01'));

        $older = (new DeletePolicy(['m_datetime_lt' => '2001-01-01']))->decide($this->files);
        $newer = (new DeletePolicy(['m_datetime_gt' => '2001-01-01']))->decide($this->files);

        $this->assertSame([$this->files[2]], $older['keep']);
        $this->assertSame([$this->files[0], $this->files[1]], $newer['keep']);
    }

    public function testPermissionsRule(): void
    {
        \chmod($this->files[1], 0600);

        $this->assertSame([$this->files[1]], (new DeletePolicy(['permissions' => '0600']))->decide($this->files)['keep']);
    }

    public function testOwnerRule(): void
    {
        $decision = (new DeletePolicy(['owner' => [\fileowner($this->files[0])]]))->decide($this->files);

        $this->assertSame($this->files, $decision['keep']);
        $this->assertSame([], $decision['delete']);
    }

    public static function invalidRules(): array
    {
        return [
            'regex' => [['filename_is' => '/[/']],
            'date' => [['m_datetime_gt' => 'not a date at all']],
            'permissions' => [['permissions' => 'rwx']],
            'owner' => [['owner' => 'root']],
            'unknown' => [['size_gt' => '10']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRules')]
    public function testInvalidRuleThrows(array $rules): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DeletePolicy([], $rules);
    }

    public function testFromFileWithInvalidJson(): void
    {
        \file_put_contents("$this->dir/policy.json", '{"keep_rule": ');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid delete policy JSON');

        DeletePolicy::fromFile("$this->dir/policy.json");
    }

    public function testFromMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeletePolicy::fromFile("$this->dir/missing.json");
    }

    public function testExamplePolicyLoads(): void
    {
        $policy = DeletePolicy::fromFile(__DIR__ . '/../../etc/delete_policy.json');

        $this->assertSame([$this->files[0]], $policy->decide($this->files)['keep']);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `mkdir -p etc && cp ../duplicate-detector/etc/delete_policy.json etc/ && composer test -- --filter DeletePolicyTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement `src/Action/DeletePolicy.php`**

```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Action;

/**
 * Decides which files of a duplicate group are kept and which deleted. Pure logic, no I/O besides stat.
 */
final class DeletePolicy
{
    private const REGEX_RULES = ['filename_is', 'filename_not_is', 'path_is', 'path_not_is'];
    private const DATE_RULES = [
        'a_datetime_gt', 'a_datetime_lt', 'c_datetime_gt', 'c_datetime_lt', 'm_datetime_gt', 'm_datetime_lt',
    ];
    private const LIST_RULES = ['owner', 'group'];

    /**
     * @var array<string, string|array>
     */
    private readonly array $keep;

    /**
     * @var array<string, string|array>
     */
    private readonly array $delete;

    /**
     * @param array<string, string|array> $keepRules
     * @param array<string, string|array> $deleteRules
     * @throws \InvalidArgumentException
     */
    public function __construct(array $keepRules = [], array $deleteRules = [])
    {
        $this->keep = self::validate($keepRules);
        $this->delete = self::validate($deleteRules);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromFile(string $path): self
    {
        $json = @\file_get_contents($path);

        if ($json === false) {
            throw new \InvalidArgumentException("Unable to read delete policy: $path");
        }

        try {
            $data = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(
                "Invalid delete policy JSON in $path: {$exception->getMessage()}",
                0,
                $exception
            );
        }

        if (!\is_array($data)) {
            throw new \InvalidArgumentException("Invalid delete policy JSON in $path: object expected");
        }

        return new self($data['keep_rule'] ?? [], $data['delete_rule'] ?? []);
    }

    /**
     * @param string[] $files group files, first one is kept when no keep rule matches
     * @return array{keep: string[], delete: string[]} files in neither list stay untouched
     */
    public function decide(array $files): array
    {
        $files = \array_values($files);
        $keep = \array_values(\array_filter($files, fn (string $file): bool => $this->matches($this->keep, $file)));

        if ($keep === []) {
            return ['keep' => [$files[0]], 'delete' => \array_slice($files, 1)];
        }

        $rest = \array_values(\array_diff($files, $keep));

        if ($this->delete !== []) {
            $rest = \array_values(\array_filter($rest, fn (string $file): bool => $this->matches($this->delete, $file)));
        }

        return ['keep' => $keep, 'delete' => $rest];
    }

    /**
     * @param array<string, string|array> $rules
     */
    private function matches(array $rules, string $file): bool
    {
        $info = new \SplFileInfo($file);

        foreach ($rules as $name => $rule) {
            if ($this->ruleMatches($name, $rule, $info)) {
                return true;
            }
        }

        return false;
    }

    private function ruleMatches(string $name, string|array $rule, \SplFileInfo $file): bool
    {
        return match ($name) {
            'filename_is' => \preg_match($rule, $file->getFilename()) === 1,
            'filename_not_is' => \preg_match($rule, $file->getFilename()) === 0,
            'path_is' => \preg_match($rule, $file->getPath()) === 1,
            'path_not_is' => \preg_match($rule, $file->getPath()) === 0,
            'permissions' => \substr(\sprintf('%o', $file->getPerms()), -3) === \substr($rule, -3),
            'owner' => \in_array($file->getOwner(), $rule),
            'group' => \in_array($file->getGroup(), $rule),
            default => $this->dateMatches($name, $rule, $file),
        };
    }

    private function dateMatches(string $name, string $rule, \SplFileInfo $file): bool
    {
        $stamp = match ($name[0]) {
            'a' => $file->getATime(),
            'c' => $file->getCTime(),
            default => $file->getMTime(),
        };

        return \str_ends_with($name, '_gt') ? $stamp > \strtotime($rule) : $stamp < \strtotime($rule);
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, string|array> non-empty rules only
     * @throws \InvalidArgumentException
     */
    private static function validate(array $rules): array
    {
        $rules = \array_filter($rules, static fn ($rule): bool => $rule !== '' && $rule !== [] && $rule !== null);

        foreach ($rules as $name => $rule) {
            $valid = match (true) {
                \in_array($name, self::REGEX_RULES, true) => \is_string($rule) && @\preg_match($rule, '') !== false,
                \in_array($name, self::DATE_RULES, true) => \is_string($rule) && \strtotime($rule) !== false,
                \in_array($name, self::LIST_RULES, true) => \is_array($rule),
                $name === 'permissions' => \is_string($rule) && \preg_match('/^0?[0-7]{3}$/', $rule) === 1,
                default => throw new \InvalidArgumentException("Unknown delete policy rule: $name"),
            };

            if (!$valid) {
                throw new \InvalidArgumentException("Invalid value of delete policy rule $name: " . \json_encode($rule));
            }
        }

        return $rules;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `composer test -- --filter DeletePolicyTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Action/DeletePolicy.php etc tests/Action
git commit -m "Added delete policy with keep and delete rules"
```

---

### Task 7: Deleter and actions (ListOnly, Interactive, AutoDelete)

**Files:**
- Create: `src/Action/Action.php`, `src/Action/Deleter.php`, `src/Action/ListOnly.php`, `src/Action/Interactive.php`, `src/Action/AutoDelete.php`
- Test: `tests/Action/DeleterTest.php`, `tests/Action/ActionsTest.php`

**Interfaces:**
- Consumes: `DuplicateGroup`, `Grouper`, `DeletePolicy`, `Fixture::style`, `BlueConsole\Style`, `BlueConsole\MultiSelect::renderMultiSelect(array): array` (returns selected index => mixed).
- Produces: `Action::handle(DuplicateGroup $group): void`; `Deleter::__construct(Style $style, ?string $backupDir = null, bool $dryRun = false)`, `Deleter::delete(string $file): bool`, `Deleter::deletedFiles(): int`, `Deleter::deletedSize(): int`; `ListOnly::__construct(Style $style, bool $withSize = true)`; `Interactive::__construct(Style $style, MultiSelect $select, Deleter $deleter)`; `AutoDelete::__construct(Style $style, DeletePolicy $policy, Deleter $deleter)`.

- [ ] **Step 1: Write failing tests**

`tests/Action/DeleterTest.php`:
```php
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
```

`tests/Action/ActionsTest.php`:
```php
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
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter 'DeleterTest|ActionsTest'`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement**

`src/Action/Action.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Action;

use BlueDuplicateDetector\DuplicateGroup;

interface Action
{
    public function handle(DuplicateGroup $group): void;
}
```

`src/Action/Deleter.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Action;

use BlueConsole\Style;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class Deleter
{
    private int $files = 0;
    private int $size = 0;

    /**
     * @param string|null $backupDir copy file to $backupDir + its absolute path before delete
     * @param bool $dryRun do everything (backup included) except delete
     */
    public function __construct(
        private readonly Style $style,
        private readonly ?string $backupDir = null,
        private readonly bool $dryRun = false,
    ) {
    }

    public function delete(string $file): bool
    {
        $name = OutputFormatter::escape($file);
        $size = (int)@\filesize($file);

        if ($this->backupDir !== null && !$this->backup($file)) {
            $this->style->errorMessage("<fg=blue>Copy</> failed, file kept: $name");
            return false;
        }

        if (!$this->dryRun && !@\unlink($file)) {
            $this->style->errorMessage("<fg=red>Remove</> failed: $name");
            return false;
        }

        $this->style->okMessage('<fg=red>Removed</>' . ($this->dryRun ? ' (test)' : '') . ": $name");
        $this->files++;
        $this->size += $size;

        return true;
    }

    public function deletedFiles(): int
    {
        return $this->files;
    }

    public function deletedSize(): int
    {
        return $this->size;
    }

    private function backup(string $file): bool
    {
        $source = \realpath($file);

        if ($source === false) {
            return false;
        }

        $destination = \rtrim($this->backupDir, '/') . $source;
        $directory = \dirname($destination);

        if (!\is_dir($directory) && !@\mkdir($directory, 0777, true)) {
            return false;
        }

        // backup dir "/" (or a symlink to it) would copy the file onto itself and then delete it
        if (\realpath($directory) === \dirname($source)) {
            return false;
        }

        if (!@\copy($source, $destination)) {
            return false;
        }

        $this->style->okMessage(
            '<fg=blue>Copy</>: ' . OutputFormatter::escape($file) . ' to ' . OutputFormatter::escape($destination)
        );

        return true;
    }
}
```

`src/Action/ListOnly.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Action;

use BlueConsole\Style;
use BlueData\Data\Formats;
use BlueDuplicateDetector\DuplicateGroup;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class ListOnly implements Action
{
    public function __construct(
        private readonly Style $style,
        private readonly bool $withSize = true,
    ) {
    }

    public function handle(DuplicateGroup $group): void
    {
        foreach ($group->files as $file) {
            $size = $this->withSize ? ' (' . Formats::dataSize($group->sizes[$file]) . ')' : '';
            $this->style->writeln(OutputFormatter::escape($file) . $size);
        }

        if ($this->withSize) {
            $this->style->newLine();
        }
    }
}
```

`src/Action/Interactive.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Action;

use BlueConsole\MultiSelect;
use BlueConsole\Style;
use BlueData\Data\Formats;
use BlueDuplicateDetector\DuplicateGroup;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class Interactive implements Action
{
    public function __construct(
        private readonly Style $style,
        private readonly MultiSelect $select,
        private readonly Deleter $deleter,
    ) {
    }

    public function handle(DuplicateGroup $group): void
    {
        $this->style->newLine();

        // options are built from $group->files in order, so selected index = index in $group->files
        $options = \array_map(
            static fn (string $file): string => OutputFormatter::escape($file)
                . ' (<info>' . Formats::dataSize($group->sizes[$file]) . '</>)',
            $group->files
        );

        $selected = \array_keys($this->select->renderMultiSelect($options));

        if (\count($selected) >= \count($group->files)) {
            $this->style->warningMessage('All copies selected, nothing deleted from this group.');
        } else {
            foreach ($selected as $index) {
                $this->deleter->delete($group->files[$index]);
            }
        }

        $this->style->newLine();
    }
}
```

`src/Action/AutoDelete.php`:
```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Action;

use BlueConsole\Style;
use BlueDuplicateDetector\DuplicateGroup;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class AutoDelete implements Action
{
    public function __construct(
        private readonly Style $style,
        private readonly DeletePolicy $policy,
        private readonly Deleter $deleter,
    ) {
    }

    public function handle(DuplicateGroup $group): void
    {
        $decision = $this->policy->decide($group->files);

        foreach ($decision['keep'] as $file) {
            $this->style->okMessage('<fg=green>Keep</>: ' . OutputFormatter::escape($file));
        }

        foreach ($decision['delete'] as $file) {
            $this->deleter->delete($file);
        }

        $this->style->newLine();
    }
}
```

- [ ] **Step 4: Run tests**

Run: `composer test -- --filter 'DeleterTest|ActionsTest'`
Expected: PASS. If `Formats::dataSize(12)` renders differently than `12.00 B`, adjust only the expected string in `testListOnlyPrintsEscapedPathsWithSizes` to the actual format.

- [ ] **Step 5: Commit**

```bash
git add src/Action tests/Action
git commit -m "Added list, interactive and automatic delete actions"
```

---

### Task 8: HtmlReport

**Files:**
- Create: `src/Report/HtmlReport.php`
- Test: `tests/Report/HtmlReportTest.php`

**Interfaces:**
- Consumes: `DuplicateGroup`, `Grouper`, `BlueData\Data\Formats::dataSize(int): string`.
- Produces: `HtmlReport::__construct(int $pageSize = 100)`, `HtmlReport::save(array $groups, string $dir): int` (pages written; throws `RuntimeException`). Files: `index.html`, `duplicates-0001.html`…; old `duplicates-*.html` removed.

- [ ] **Step 1: Write failing `tests/Report/HtmlReportTest.php`**

```php
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

    private function groups(): array
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
        $pages = (new HtmlReport(2))->save($this->groups(), $this->out);

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

        (new HtmlReport(2))->save($this->groups(), $this->out);

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

        (new HtmlReport())->save($this->groups(), "$this->dir/file/report");
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter HtmlReportTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement `src/Report/HtmlReport.php`** (ported from `../duplicate-detector/src/DuplicatedFilesTool.php` `saveHtmlReport` / `writeHtml`; sorting now done by `Grouper`)

```php
<?php

declare(strict_types=1);

namespace BlueDuplicateDetector\Report;

use BlueData\Data\Formats;
use BlueDuplicateDetector\DuplicateGroup;

final class HtmlReport
{
    public function __construct(private readonly int $pageSize = 100)
    {
    }

    /**
     * @param DuplicateGroup[] $groups in display order (Grouper output)
     * @return int number of written pages
     * @throws \RuntimeException
     */
    public function save(array $groups, string $dir): int
    {
        if (!\is_dir($dir) && !@\mkdir($dir, 0777, true)) {
            throw new \RuntimeException("Unable to create HTML report directory: $dir");
        }

        foreach (\glob("$dir/duplicates-*.html") ?: [] as $oldPage) {
            @\unlink($oldPage);
        }

        $pages = \array_chunk($groups, $this->pageSize);
        $pagesCount = \count($pages);
        $totalFiles = 0;
        $totalSize = 0;
        $indexRows = '';

        foreach ($pages as $index => $pageGroups) {
            $page = $index + 1;
            $body = '';
            $pageSize = 0;

            foreach ($pageGroups as $groupIndex => $group) {
                $number = $index * $this->pageSize + $groupIndex + 1;
                $rows = '';

                foreach ($group->files as $file) {
                    $rows .= '<tr><td>' . \htmlspecialchars($file) . '</td><td class="size">'
                        . Formats::dataSize($group->sizes[$file]) . '</td></tr>';
                }

                $pageSize += $group->size;
                $totalFiles += \count($group->files);
                $body .= "<h2>#$number <small>" . \htmlspecialchars($group->key) . '</small></h2>'
                    . "<table>$rows</table>";
            }

            $totalSize += $pageSize;
            $nav = '<p class="nav"><a href="index.html">index</a>'
                . ($page > 1 ? ' | <a href="' . self::pageName($page - 1) . '">&lsaquo; previous</a>' : '')
                . " | page $page / $pagesCount"
                . ($page < $pagesCount ? ' | <a href="' . self::pageName($page + 1) . '">next &rsaquo;</a>' : '')
                . '</p>';

            $this->write("$dir/" . self::pageName($page), "Duplicated files - page $page", $nav . $body . $nav);

            $firstDir = \htmlspecialchars(\dirname(\reset($pageGroups)->files[0]));
            $lastDir = \htmlspecialchars(\dirname(\end($pageGroups)->files[0]));
            $indexRows .= '<tr><td><a href="' . self::pageName($page) . "\">page $page</a></td>"
                . "<td>$firstDir<br>$lastDir</td><td class=\"size\">" . Formats::dataSize($pageSize) . '</td></tr>';
        }

        $summary = '<p>Duplications: <b>' . \count($groups) . "</b>, duplicated files: <b>$totalFiles</b>, size: <b>"
            . Formats::dataSize($totalSize) . '</b>, generated: ' . \date('Y-m-d H:i:s') . '</p>';

        $this->write("$dir/index.html", 'Duplicated files', "$summary<table>$indexRows</table>");

        return $pagesCount;
    }

    private static function pageName(int $page): string
    {
        return \sprintf('duplicates-%04d.html', $page);
    }

    /**
     * @throws \RuntimeException
     */
    private function write(string $path, string $title, string $body): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>$title</title>
<style>
:root { color-scheme: dark; }
body { font-family: sans-serif; margin: 2em; background: #1e1f22; color: #d4d4d4; }
b { color: #fff; }
h2 { font-size: 1em; margin: 1.5em 0 .3em; }
h2 small { color: #8a8a8a; font-weight: normal; font-family: monospace; }
table { border-collapse: collapse; width: 100%; }
td { border: 1px solid #3a3b3f; padding: .3em .6em; font-family: monospace; word-break: break-all; }
td.size { width: 8em; text-align: right; white-space: nowrap; color: #9cdcfe; }
tr:nth-child(even) { background: #26272b; }
a { color: #6cb6ff; }
.nav { font-size: 1.1em; }
</style>
</head>
<body>
<h1>$title</h1>
$body
</body>
</html>
HTML;

        if (@\file_put_contents($path, $html) === false) {
            throw new \RuntimeException("Unable to save HTML report: $path");
        }
    }
}
```

- [ ] **Step 4: Run tests**

Run: `composer test -- --filter HtmlReportTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Report tests/Report
git commit -m "Added paginated HTML report"
```

---

### Task 9: Console command, ConsoleProgress, README

**Files:**
- Create: `src/Command/ConsoleProgress.php`, `src/Command/DuplicatedFilesCommand.php`, `README.md`, `CHANGELOG.md`
- Test: `tests/Command/DuplicatedFilesCommandTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: `DuplicatedFilesCommand::__construct(string $name = 'duplicate', array $defaultSources = [], string $defaultHtmlDir = '/out')`, constants `DELETE_POLICY_EXAMPLE_FILE`, `HTML_PAGE_SIZE = 100`; `ConsoleProgress::__construct(OutputInterface $output, bool $showMessage = false)`.

- [ ] **Step 1: Write failing `tests/Command/DuplicatedFilesCommandTest.php`**

```php
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

    private function run(array $input, array $defaultSources = []): CommandTester
    {
        $tester = new CommandTester(new DuplicatedFilesCommand('duplicate', $defaultSources));
        $tester->execute($input, ['decorated' => false]);

        return $tester;
    }

    public function testListOnly(): void
    {
        $tester = $this->run(['source' => [$this->dir], '--list-only' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString("$this->dir/b/c/three.txt", $tester->getDisplay());
        $this->assertStringContainsString('Duplicated files: 7', $tester->getDisplay());
    }

    public function testThreadsGiveSameSummary(): void
    {
        $tester = $this->run(['source' => [$this->dir], '--thread' => '3', '--skip-empty' => true]);

        $this->assertStringContainsString('Duplicated files: 5', $tester->getDisplay());
    }

    public function testDefaultSources(): void
    {
        $tester = $this->run(['--size' => true], [$this->dir]);

        $this->assertStringContainsString('Duplicated files: 7', $tester->getDisplay());
    }

    public function testCheckByName(): void
    {
        $dir = Fixture::create(['a/photo-001.jpg' => '1', 'b/photo-002.jpg' => '2', 'c/report.pdf' => '3']);

        try {
            $tester = $this->run(['source' => [$dir], '--check-by-name' => '90']);

            $this->assertStringContainsString('Duplicated files: 2', $tester->getDisplay());
        } finally {
            Fixture::remove($dir);
        }
    }

    public function testHtmlReport(): void
    {
        $out = "$this->dir/../" . \basename($this->dir) . '-html';

        try {
            $this->run(['source' => [$this->dir], '--html' => $out]);

            $this->assertFileExists("$out/index.html");
            $this->assertFileExists("$out/duplicates-0001.html");
        } finally {
            Fixture::remove($out);
        }
    }

    public function testAutoDeleteLeavesOneFilePerGroup(): void
    {
        $this->run(['source' => [$this->dir], '--auto-delete' => true]);

        $this->assertFileExists("$this->dir/a/one.txt");
        $this->assertFileDoesNotExist("$this->dir/b/two.txt");
        $this->assertFileDoesNotExist("$this->dir/b/c/three.txt");
        $this->assertFileExists("$this->dir/e/unique.txt");
        $this->assertFileExists("$this->dir/g/prefix-2.bin");
    }

    public function testAutoDeleteTestModeKeepsFiles(): void
    {
        $tester = $this->run(['source' => [$this->dir], '--auto-delete' => true, '--auto-delete-test' => true]);

        $this->assertFileExists("$this->dir/b/two.txt");
        $this->assertStringContainsString('Removed (test)', $tester->getDisplay());
    }

    public function testDeletePolicyExample(): void
    {
        $tester = $this->run(['--delete-policy-example' => true]);

        $this->assertStringContainsString('"keep_rule"', $tester->getDisplay());
    }

    public function testInvalidPolicyFailsBeforeDeleting(): void
    {
        \file_put_contents("$this->dir/policy.json", '{"keep_rule": {"filename_is": "/[/"}}');

        try {
            $this->run(['source' => [$this->dir], '--auto-delete' => true, '--delete-policy' => "$this->dir/policy.json"]);
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

        $this->run(['source' => [$this->dir]] + $input);
    }

    public function testMissingSourceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->run([]);
    }

    public function testCustomName(): void
    {
        $this->assertSame('fs:duplicated', (new DuplicatedFilesCommand('fs:duplicated'))->getName());
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter DuplicatedFilesCommandTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement `src/Command/ConsoleProgress.php`**

```php
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
```

- [ ] **Step 4: Implement `src/Command/DuplicatedFilesCommand.php`**

```php
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
     */
    public function __construct(
        string $name = 'duplicate',
        private readonly array $defaultSources = [],
        private readonly string $defaultHtmlDir = '/out',
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('Search files duplication and make some action on it.')
            ->addArgument('source', InputArgument::IS_ARRAY, 'Directories or files to check')
            ->addOption('interactive', 'i', InputOption::VALUE_NONE, 'Show multi-checkbox with duplicated files, selected will be deleted')
            ->addOption('skip-empty', 's', InputOption::VALUE_NONE, 'Skip empty files')
            ->addOption('check-by-name', 'N', InputOption::VALUE_REQUIRED, 'Compare file names instead of content, value is minimal similarity in percent (0-100)')
            ->addOption('progress-info', 'p', InputOption::VALUE_NONE, 'Show message on progress bar (file name or thread status)')
            ->addOption('thread', 't', InputOption::VALUE_REQUIRED, 'Number of processes calculating hashes, 0 = current process', '0')
            ->addOption('redis', 'r', InputOption::VALUE_OPTIONAL, 'Exchange data between processes through Redis (host[:port], default 127.0.0.1:6379) instead of temporary files', false)
            ->addOption('size', 'S', InputOption::VALUE_NONE, 'Hash only files which size is shared with another file (faster)')
            ->addOption('min-size', 'm', InputOption::VALUE_REQUIRED, 'Minimal size of checked files in bytes', '0')
            ->addOption('chunk', 'c', InputOption::VALUE_REQUIRED, 'Hash only first given bytes of each file (faster for large files, less accurate)', '0')
            ->addOption('list-only', 'l', InputOption::VALUE_NONE, 'Show only paths of duplicated files')
            ->addOption('auto-delete', 'd', InputOption::VALUE_NONE, 'Automatically delete duplicated files, first file of each group (or files matching keep rules) is kept')
            ->addOption('delete-backup', 'b', InputOption::VALUE_REQUIRED, 'Copy deleted files into given directory (keeping absolute path) before delete')
            ->addOption('delete-policy', 'D', InputOption::VALUE_REQUIRED, 'JSON file with keep/delete rules for automatic delete')
            ->addOption('delete-policy-example', 'E', InputOption::VALUE_NONE, 'Print example delete policy file')
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
        $style = new Style($input, $output);

        $style->title('Check file duplications');
        $style->infoMessage('Reading directories.');

        $files = (new Scanner(
            $options['min-size'],
            (bool)$input->getOption('size'),
            (bool)$input->getOption('skip-empty')
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

        $threads = $this->intOption($input, 'thread');
        $redis = $input->getOption('redis');

        if ($redis !== false && $threads === 0) {
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
                $redis === false => new Threads($threads, new FileTransport()),
                default => new Threads($threads, RedisTransport::fromDsn($redis)),
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
            $deleter = new Deleter($style);

            return [new Interactive($style, (new MultiSelect($style))->toggleShowInfo(false), $deleter), $deleter];
        }

        if ($input->getOption('auto-delete')) {
            $deleter = new Deleter($style, $input->getOption('delete-backup'), (bool)$input->getOption('auto-delete-test'));

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
```

- [ ] **Step 5: Run tests**

Run: `composer test`
Expected: PASS (whole suite).

- [ ] **Step 6: Write `README.md` and `CHANGELOG.md`**

`README.md`:
````markdown
# duplicate-detector-lib

Find duplicated files and list them, save them as HTML report, or delete them (interactively or by delete policy).
Hashing (`sha3-256`) runs in the current process or in N worker processes, which exchange data through temporary
files (default) or Redis (`--redis`, requires `ext-redis`).

Used by [duplicate-detector](https://github.com/bluetree-service/duplicate-detector) and
[tools-cli](https://github.com/chajr/tools-cli).

## Install

```
composer require bluetree-service/duplicate-detector-lib
```

## Console command

```php
use BlueDuplicateDetector\Command\DuplicatedFilesCommand;
use Symfony\Component\Console\Application;

$application = new Application();
$application->addCommand(new DuplicatedFilesCommand('duplicate', ['/duplicates'], '/out'));
$application->run();
```

Constructor: command name, default sources (when no `source` argument), default HTML directory (`-H` without value).

| Option | Description |
|---|---|
| `-t N` | N worker processes (0 = current process) |
| `-r [host:port]` | exchange data between workers through Redis (default `127.0.0.1:6379`), requires `-t` |
| `-S` | hash only files with non unique size |
| `-m BYTES` | minimal file size |
| `-s` | skip empty files |
| `-c BYTES` | hash only first BYTES of each file |
| `-N PERCENT` | compare file names (`similar_text`) instead of content |
| `-l` | list paths only |
| `-i` | interactive delete (selecting all copies of a group is refused) |
| `-d` | automatic delete, keeps first file (sorted by directory, then name) or files matching keep rules |
| `-D FILE` | delete policy JSON (`-E` prints example) |
| `-b DIR` | copy deleted files to DIR + absolute path before delete; failed copy keeps the file |
| `-T` | test automatic delete, nothing is deleted |
| `-H [DIR]` | HTML report, `index.html` + `duplicates-NNNN.html` (100 duplications per page) |
| `-p` | show file name / thread status on progress bar |

## Delete policy

`keep_rule` and `delete_rule`, empty values are ignored, a file matches a rule set when it matches any rule:
`filename_is`, `filename_not_is`, `path_is`, `path_not_is` (regex), `{a,c,m}_datetime_gt` / `_lt`
(file access/change/modify time after / before date), `permissions` (`"644"`), `owner`, `group` (uid/gid lists).

Files matching keep rules are kept, remaining ones matching delete rules are deleted (all remaining when there are
no delete rules). When no file matches keep rules, the first file is kept and all others are deleted.

## Library usage

```php
use BlueDuplicateDetector\{Scanner, Grouper};
use BlueDuplicateDetector\Hasher\{Threads, FileTransport};
use BlueDuplicateDetector\Progress\NullProgress;

$files = (new Scanner(minSize: 1, sameSizeOnly: true))->scan(['/data']);
$result = (new Threads(4, new FileTransport()))->hash($files, 0, new NullProgress());
$groups = (new Grouper())->group($result->hashes); // DuplicateGroup[]: key, files, sizes, size
```

## Tests

`composer test` (Redis tests are skipped without `ext-redis`/Redis), `make run-and-test php_version=84` in docker.
````

`CHANGELOG.md`:
```markdown
# Changelog

## 0.1.0

- Extracted duplicated files detection from tools-cli and duplicate-detector.
- Worker processes with file or Redis transport, single process mode.
- Interactive delete, automatic delete with keep/delete policy, backup and test mode.
- Paginated HTML report.
```

- [ ] **Step 7: Manual check through a tiny runner**

Run:
```bash
php -r 'require "vendor/autoload.php"; $a = new Symfony\Component\Console\Application(); $a->addCommand(new BlueDuplicateDetector\Command\DuplicatedFilesCommand()); $a->setAutoExit(false); $a->run(new Symfony\Component\Console\Input\ArgvInput(["x", "duplicate", "-S", "-t", "2", "-p", "tests"]));'
```
Expected: title, progress bar, summary lines, no warnings. If `Application::addCommand()` does not exist in the installed Symfony version, use `add()`.

- [ ] **Step 8: Commit**

```bash
git add src/Command tests/Command README.md CHANGELOG.md
git commit -m "Added console command and documentation"
```

---

### Task 10: Full verification and publishing (requires user confirmation)

- [ ] **Step 1: Full test run in all PHP versions**

Run: `make test-all`
Expected: PASS for 82, 83, 84, 85; Redis tests executed (not skipped).

- [ ] **Step 2: Ask the user before any outward action**

Publishing is outward-facing. Ask the user to confirm, then:
1. Create `github.com/bluetree-service/duplicate-detector-lib` (public) — user or `gh repo create bluetree-service/duplicate-detector-lib --public --source . --push`.
2. Tag `0.1.0.0` (bluetree tags use 4 parts) and push tags.
3. Submit to Packagist (user, web UI) and enable GitHub hook.

- [ ] **Step 3: Record migration follow-up**

Migration of `duplicate-detector` (replace `src/Duplicated*`, `DuplicatedFilesTool`, keep `bin/detector` + Dockerfile) and `tools-cli` (requires `symfony/console ^7.4` upgrade first) is a separate spec/plan.
