# Changelog

## 0.2.0

- `-r` / `--redis` is a flag only, Redis connection is given to `DuplicatedFilesCommand` constructor as array
  (`host`, `port`, `user`, `password`, `database`).
- Redis authentication (password or ACL user + password) and database selection.
- Workers get Redis connection through environment variable instead of process arguments.
- BC: `RedisTransport::fromDsn()` and public constructor replaced by `RedisTransport::fromArray()`,
  `Transport::workerEnv()` added.

## 0.1.2

- Redis transport: own queue per thread (files split evenly, last thread gets the same or fewer), progress bar with total for every thread.

## 0.1.1

- Separate, colored progress bar for every hashing thread (files done / assigned, counter only with Redis transport).
- Progress bars erased from terminal after hashing.
- `Progress` interface: `start()` takes number of threads, new `thread()` method.

## 0.1.0

- Extracted duplicated files detection from tools-cli and duplicate-detector.
- Worker processes with file or Redis transport, single process mode.
- Interactive delete, automatic delete with keep/delete policy, backup and test mode.
- Paginated HTML report.
