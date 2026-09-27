# Changelog

## 0.1.1

- Separate, colored progress bar for every hashing thread (files done / assigned, counter only with Redis transport).
- Progress bars erased from terminal after hashing.
- `Progress` interface: `start()` takes number of threads, new `thread()` method.

## 0.1.0

- Extracted duplicated files detection from tools-cli and duplicate-detector.
- Worker processes with file or Redis transport, single process mode.
- Interactive delete, automatic delete with keep/delete policy, backup and test mode.
- Paginated HTML report.
