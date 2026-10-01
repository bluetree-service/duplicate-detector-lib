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
$application->addCommand(new DuplicatedFilesCommand('duplicate', ['/duplicates'], '/out', [
    'host' => 'redis',
    'password' => \getenv('REDIS_PASSWORD') ?: null,
]));
$application->run();
```

Constructor: command name, default sources (when no `source` argument), default HTML directory (`-H` without value),
Redis connection used with `-r`: `host` (`127.0.0.1`), `port` (`6379`), `user` (ACL, requires `password`), `password`,
`database` (`0`). Missing keys take defaults, unknown keys throw. Workers get the connection through environment
variable, never through process arguments.

| Option | Description |
|---|---|
| `-t N` | N worker processes (0 = current process) |
| `-r` | exchange data between workers through Redis (connection from constructor), requires `-t` |
| `-S` | hash only files with non unique size |
| `-m BYTES` | minimal file size |
| `-s` | skip empty files |
| `-x PATTERN` | skip directories matching pattern (`fnmatch`, name or full path: `.git`, `*/cache*`), repeatable |
| `-I PATTERN` | check only files with name matching pattern (`*.jpg`), repeatable |
| `-C` | case insensitive `-x` / `-I` (case sensitive by default) |
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

`-x` and `-I` apply to directory scan only, a file given as `source` is always checked. Excluded directories are not
read at all, so on big trees they also save time and memory.

## Delete policy

`keep_rule` and `delete_rule`, empty values are ignored, a file matches a rule set when it matches any rule:
`filename_is`, `filename_not_is`, `path_is`, `path_not_is` (regex), `{c,m}_datetime_gt` / `_lt`
(file change/modify time after / before date; access time rules are not supported, hashing changes it), `permissions` (`"644"`), `owner`, `group` (uid/gid lists).

Files matching keep rules are kept (the first file when none matches), remaining ones matching delete rules are
deleted (all remaining when there are no delete rules).

Before any delete (`-d`, `-i`) the file is compared by full content with a kept copy, so `-c` and `-N` groups,
the same file reached by two paths, and files changed since hashing are skipped instead of deleted.

## Library usage

```php
use BlueDuplicateDetector\{Scanner, Grouper};
use BlueDuplicateDetector\Hasher\{Threads, FileTransport, RedisTransport};
use BlueDuplicateDetector\Progress\NullProgress;

$files = (new Scanner(minSize: 1, sameSizeOnly: true, exclude: ['.git'], include: ['*.jpg']))->scan(['/data']);
$result = (new Threads(4, new FileTransport()))->hash($files, 0, new NullProgress());
// or: new Threads(4, RedisTransport::fromArray(['host' => 'redis', 'password' => 'secret']))
$groups = (new Grouper())->group($result->hashes); // DuplicateGroup[]: key, files, sizes, size
```

## Tests

`composer test` (Redis tests are skipped without `ext-redis`/Redis), `make run-and-test php_version=84` in docker.
