# duplicate-detector-lib — design

Date: 2026-09-25

## Goal

Extract the duplicated-files detection logic shared by `chajr/tools-cli` (`fs:duplicated`) and the
`duplicate-detector` app into one public Composer library, so both projects stop diverging.

- Package / repo: `bluetree-service/duplicate-detector-lib` (github.com/bluetree-service/duplicate-detector-lib)
- Namespace: `BlueDuplicateDetector\`
- Local directory: `projects/blue/duplicate-detector-lib`
- Conventions follow `class2/cache`: PHP `^8.2`, PHPUnit `^11.5`, `.github/workflows/build.yml`,
  Apache-2.0, composer scripts `test` / `coverage`.

## Scope

Library contains the union of both projects:

- from **tools-cli**: Redis-queue based multithreading;
- from **duplicate-detector**: auto delete (`AutoDel`) with keep/delete policy, delete backup, auto delete test mode,
  paginated HTML report, `delete-policy-example`;
- a ready Symfony Console command wiring it all, registered by both applications.

Out of scope:

- migrating tools-cli and duplicate-detector to the library (separate step after `0.1.0`);
- remaining `todo-old.md` items (file patterns, link after delete, inverse selection, show hash).

## Dependencies

- require: `php ^8.2`, `symfony/console ^7.4|^8.0`, `bluetree-service/symfony-console-style ^0.7`
  (Style, MultiSelect), `bluetree-service/filesystem ^0.4` (Structure, Fs), `bluetree-service/data ^0.5` (Formats),
  `react/child-process ^0.6`, `ext-json`
- suggest: `ext-redis` (needed only for `--redis`)
- dropped: `bluetree-service/register` (plain `new`), `serafim/pipe` (plain `json_decode`), `ramsey/uuid`
  (`bin2hex(random_bytes(16))`)

Consequence: tools-cli must move to `symfony/console ^7.4` before it can use the library (migration step).

## Architecture

```
src/
  Scanner.php                 read directories, min-size, same-size prefilter, skip-empty
  Hasher/Hasher.php           interface: hash(array $files, int $chunk): HashResult
  Hasher/SingleProcess.php    in-process loop
  Hasher/Threads.php          N child processes (react/child-process), uses a Transport
  Hasher/FileTransport.php    per-thread JSON files in sys_get_temp_dir()/dup-{session}/
  Hasher/RedisTransport.php   shared Redis queue
  Hasher/HashResult.php       hashes (hash => files) + errors
  Grouper.php                 array_unique, drop single-file groups, check-by-name (Name)
  Name.php                    similar_text based name matching
  DuplicateGroup.php          hash, files, size (computed once)
  Action/Action.php           interface: handle(DuplicateGroup): void, counters(): Counters
  Action/ListOnly.php         former NoInteractive
  Action/Interactive.php      MultiSelect, delete selected
  Action/AutoDelete.php       former AutoDel, backup + test mode
  Action/DeletePolicy.php     keep_rule / delete_rule matching, loaded from JSON
  Action/Counters.php         duplicated files/size, deleted files/size
  Report/HtmlReport.php       paginated HTML (index.html + duplicates-NNNN.html)
  Progress/Progress.php       listener interface (start, advance, message, threadStatus, finish)
  Progress/NullProgress.php
  Command/DuplicatedFilesCommand.php   Symfony command, console Progress implementation
bin/hash-worker.php           single worker script, mode "file" or "redis"
etc/delete_policy.json        example policy
```

Core (`Scanner`, `Hasher/*`, `Grouper`, `Name`, `DeletePolicy`, `HtmlReport`) has no Symfony dependency and reports
progress only through `Progress`. `ListOnly` / `Interactive` / `AutoDelete` write through `BlueConsole\Style`
and belong to the console layer.

`DuplicatedFilesCommand` takes the command name in the constructor (`duplicate` in the app, `fs:duplicated`
in tools-cli) and optional default sources (the app uses `['/duplicates']`, HTML default dir `/out`).

## Options (command)

Kept as today: `source[]`, `-i interactive`, `-s skip-empty`, `-N check-by-name=<similarity>`, `-p progress-info`,
`-t thread=<n>`, `-S size`, `-m min-size`, `-c chunk`, `-l list-only`, `-d auto-delete`, `-b delete-backup=<dir>`,
`-D delete-policy=<file>`, `-E delete-policy-example`, `-T auto-delete-test`, `-H html[=<dir>]`.

New: `-r redis[=host:port]` (default `127.0.0.1:6379`). Only changes the transport between processes.

## Data flow

1. **Scanner**: sources → file list → `min-size` filter → optional same-size prefilter (`-S`) → `skip-empty`.
   `skip-empty` moves here so it works identically in every mode.
2. **Hasher**: `sha3-256` of the whole file or its first `chunk` bytes.
   - `-t 0` → `SingleProcess`.
   - `-t N` → `Threads` with N workers, always multi-process. Transport:
     - `FileTransport` (default): list split into N chunks, each written to `dup-{session}/in-{n}.json`;
       worker writes `out-{n}.json`; parent merges; session dir removed in `finally`.
     - `RedisTransport` (`--redis`): paths pushed to `{session}-paths`, workers `lPop` until empty,
       results in hash `{session}-hashes` field `thread-{n}`, errors in set `{session}-errors`,
       processed counter `{session}-processed`; parent merges and deletes all session keys in `finally`.
   - Worker progress: one JSON line per file on stdout, `{"thread":n,"done":k}`; final line
     `{"thread":n,"status":"ok"}`. Parent buffers stdout by line (chunks may split/merge lines) and forwards to
     `Progress::threadStatus`.
3. **Grouper**: `array_unique` per hash, drop groups with one file, optional name matching →
   `DuplicateGroup[]`.
4. **Output**: optional `HtmlReport` (before actions, so it lists files before deletion), then the selected
   action for each group; command prints summary from `Counters`.

## Error handling

- Unreadable file while hashing → recorded in `HashResult::errors`, file skipped, run continues (all modes).
- Worker exit code ≠ 0 or missing output file → `RuntimeException` after the loop; no silent partial result.
- `--redis` without `ext-redis` or without connection → `RuntimeException` before starting; no fallback to files.
- Incompatible options (interactive+list-only, interactive+auto-delete, delete-policy/backup without auto-delete)
  → `InvalidArgumentException`.
- Invalid delete policy JSON → `InvalidArgumentException` (replaces current `exit`).
- HTML directory not creatable → error message, run continues (as today).

## Testing

PHPUnit 11, fixtures in `tests/test-files` (copied from duplicate-detector), temp dirs under `tests/var`.

- Scanner: min-size, same-size prefilter, skip-empty.
- Grouper / Name: grouping, single-file groups dropped, name matching.
- Hashers: `SingleProcess`, `Threads+FileTransport`, `Threads+RedisTransport` return identical results for the
  same fixtures, also with `chunk`; Redis test skipped when Redis is not reachable
  (`tests/docker-compose.yaml` with redis, as in cache).
- Transport cleanup: no session dir / Redis keys left after run.
- DeletePolicy: keep_rule / delete_rule matching; AutoDelete test mode deletes nothing; backup copies structure.
- HtmlReport: page count, sorting by directory, old pages removed.
- Command smoke test via `CommandTester` (list-only, html, incompatible options).

## Carried-over fixes

- `check-by-name` in duplicate-detector overwrote the whole `$data['names']`; tools-cli keys it by directory
  (`getPath()`), so files from one directory overwrite each other. Library keys by full path
  (`names[pathname] = filename`).
- Hard-coded Redis ports (6378 / 6379) replaced by `--redis` option.
- Redis session keys and temp JSON files are always cleaned up.
