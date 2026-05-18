# Logging Implementation Plan

**Created:** 2026-05-17  
**Status:** Draft  
**Scope:** Structured JSONL logging via Monolog, Castor log tasks, exception logging

## Overview

Add Monolog-based structured logging to agent-core. Logs are written as JSONL files
under `.hatfield/logs/` (CWD-relative, PHAR-safe). Info level by default. Castor tasks
provide CLI log viewing, filtering, and searching.

## Reference

Analyzed `symfony/ai-monolog-mate-extension` for patterns. Key takeaways:
- Dual-format parser (JSONL + Monolog line format) — we only need JSONL since we control the format
- `LogEntry` value object with `SearchCriteria` specification pattern for filtering
- Generator-based streaming for memory-safe large-file reads
- `tail()` with reverse buffer for recent-entry viewing
- Channel-level filtering and date-range support

## Architecture

### New dependency

```
composer require monolog/monolog
```

Monolog provides the PSR-3 `LoggerInterface` implementation. Since we don't use
`FrameworkBundle` or `MonologBundle`, we wire Monolog directly in the DI container.

### Log directory

```
.hatfield/logs/agent.log          # Main application log (JSONL)
.hatfield/logs/agent-YYYY-MM-DD.log  # Rotated by date (Monolog RotatingFileHandler)
```

- Resolved as `getcwd() . '/.hatfield/logs'` at runtime — works for both `bin/console`
  and future PHAR execution (PHAR CWD is the project directory, not the PHAR interior).
- The `.hatfield/logs/` entry already exists in `.hatfield/.gitignore`.

### Log format

Each line is a self-contained JSON object (JSONL):

```json
{"datetime":"2026-05-17T14:30:45+00:00","channel":"app","level":"INFO","message":"Agent loop started","context":{"run_id":"abc123"},"extra":[]}
{"datetime":"2026-05-17T14:30:46+00:00","channel":"app","level":"ERROR","message":"Unhandled exception","context":{"exception":"RuntimeException","file":"src/...","line":42,"trace":"..."},"extra":[]}
```

Uses Monolog's `JsonFormatter` with `includeStacktraces: true` and `normalizeException: true`.

### Symfony DI wiring

Create `config/packages/monolog.yaml`:

```yaml
services:
  Monolog\Logger:
    arguments:
      - 'app'  # channel name
    calls:
      - pushHandler: ['@Ineersa\CodingAgent\Logging\HatfieldLogHandler']

  Psr\Log\LoggerInterface:
    alias: Monolog\Logger

  Ineersa\CodingAgent\Logging\HatfieldLogHandler:
    arguments:
      $logDir: '%kernel.project_dir%/.hatfield/logs'
      $level: !php/const Monolog\Logger::INFO
```

The `HatfieldLogHandler` is a custom `StreamHandler` subclass that:
1. Resolves the log directory from `$projectDir` (injected from `%kernel.project_dir%`)
2. Creates the directory if it doesn't exist
3. Uses `RotatingFileHandler` behavior (daily rotation, keeps 14 days)
4. Sets `JsonFormatter` with `includeStacktraces: true`

### Implementation steps

---

## Phase 1: Monolog setup

### 1.1 Install Monolog

```bash
composer require monolog/monolog
```

### 1.2 Create `HatfieldRotatingLogHandler`

**File:** `src/CodingAgent/Logging/HatfieldRotatingLogHandler.php`

Extends `Monolog\Handler\RotatingFileHandler`:
- Constructor takes `$logDir` string and `$level` int
- Sets `JsonFormatter` with batch normalization, stack traces, and UTF-8 encoding
- Ensures `$logDir` exists on first write (`mkdir` with ` recursive: true`)

```php
<?php

namespace Ineersa\CodingAgent\Logging;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;

final class HatfieldRotatingLogHandler extends RotatingFileHandler
{
    public function __construct(string $logDir, int $level = Logger::INFO, int $maxFiles = 14)
    {
        parent::__construct(
            $logDir . '/agent.log',
            $maxFiles,
            $level,
            true,
            0644,
        );

        $formatter = new JsonFormatter();
        $formatter->includeStacktraces(true);
        $formatter->setNormalizationLineBreaks(true);
        $this->setFormatter($formatter);
    }

    protected function write(LogRecord $record): void
    {
        $dir = dirname($this->url);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        parent::write($record);
    }
}
```

### 1.3 Create DI config

**File:** `config/packages/monolog.yaml`

```yaml
services:
  Ineersa\CodingAgent\Logging\HatfieldRotatingLogHandler:
    arguments:
      $logDir: '%kernel.project_dir%/.hatfield/logs'
      $level: !php/const Monolog\Logger::INFO

  Monolog\Logger:
    arguments:
      - 'app'
    calls:
      - pushHandler: ['@Ineersa\CodingAgent\Logging\HatfieldRotatingLogHandler']

  Psr\Log\LoggerInterface:
    alias: Monolog\Logger
```

### 1.4 Verify existing code picks up the logger

The following services already accept `?LoggerInterface` and will automatically
receive the Monolog logger once the alias is registered:

- `Ineersa\AgentCore\Application\Handler\RunTracer` — `?LoggerInterface $logger = null`
- `Ineersa\AgentCore\Application\Pipeline\RunCommit` — `?LoggerInterface $logger = null`

No changes needed in these files — autowiring resolves them.

---

## Phase 2: Exception logging

### 2.1 Agent command exception handler

**File:** `src/CodingAgent/CLI/AgentCommand.php`

Add `LoggerInterface` injection. Wrap the main `__invoke()` execution in a
try-catch that logs unhandled exceptions before re-throwing:

```php
public function __construct(
    // ... existing args ...
    private readonly ?LoggerInterface $logger = null,
) {}
```

In `__invoke()`, add a top-level try-catch around the TUI/headless dispatch:

```php
try {
    // existing execution logic
} catch (\Throwable $e) {
    $this->logger?->error('Unhandled exception in agent command', [
        'exception' => $e,
    ]);
    throw $e;
}
```

### 2.2 InteractiveMode exception logging

**File:** `src/Tui/Application/InteractiveMode.php`

Add `LoggerInterface` injection. Replace the silent `\Throwable` catches
(~line 155) with logged catches:

```php
} catch (\Throwable $e) {
    $this->logger?->warning('Failed to resume run', [
        'exception' => $e,
        'run_id' => $existingRunId,
    ]);
    // ... existing fallback transcript entry ...
}
```

### 2.3 RuntimeEventPoller exception logging

**File:** `src/Tui/Runtime/RuntimeEventPoller.php`

Same pattern — inject `?LoggerInterface` and log the silent `\Throwable` catch
(~line 116).

---

## Phase 3: Structured log reading — Castor tasks

### 3.1 Create `LogParser` service

**File:** `src/CodingAgent/Logging/LogParser.php`

Parses JSONL lines into structured value objects. Borrows the specification pattern
from `ai-monolog-mate-extension` but simplified for JSONL-only:

```php
final class LogEntry
{
    public function __construct(
        public readonly \DateTimeImmutable $datetime,
        public readonly string $channel,
        public readonly string $level,
        public readonly string $message,
        public readonly array $context = [],
        public readonly array $extra = [],
        public readonly ?string $sourceFile = null,
        public readonly ?int $lineNumber = null,
    ) {}
}

final class LogParser
{
    public function parse(string $line, ?string $sourceFile = null, ?int $lineNumber = null): ?LogEntry;
    // Tries JSON decode, maps to LogEntry
}

final class LogFilter
{
    public function __construct(
        public readonly ?string $level = null,
        public readonly ?string $search = null,
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?int $limit = null,
    ) {}
    
    public function matches(LogEntry $entry): bool;
}
```

### 3.2 Create `LogReader` service

**File:** `src/CodingAgent/Logging/LogReader.php`

Reads `.hatfield/logs/*.log` files, yields `LogEntry` via Generator:

```php
final class LogReader
{
    public function __construct(
        private readonly LogParser $parser,
        private readonly string $logDir,
    ) {}

    /** @return list<string> Log file paths sorted by mtime (newest first) */
    public function getLogFiles(): array;

    /** @return \Generator<LogEntry> */
    public function readFiles(array $files, ?LogFilter $filter = null): \Generator;

    /** @return list<LogEntry> */
    public function tail(int $lines = 50, ?LogFilter $filter = null): array;
}
```

`tail()` reads the newest file, maintains a 2× buffer, filters in reverse,
then reverses the result (same pattern as the reference implementation).

### 3.3 Castor log tasks

Add to `.castor/tasks.php` (or create `.castor/log-tasks.php` and import):

#### `castor log:tail` — Show recent log entries

```bash
castor log:tail [--level=ERROR] [--lines=50] [--search=term]
```

Renders a table:

```
┌──────────────────────┬───────┬─────────────────────────────────────┐
│ Time                 │ Level │ Message                             │
├──────────────────────┼───────┼─────────────────────────────────────┤
│ 2026-05-17 14:30:45  │ INFO  │ Agent loop started                  │
│ 2026-05-17 14:30:46  │ ERROR │ Unhandled exception                 │
└──────────────────────┴───────┴─────────────────────────────────────┘
```

Implementation: calls `LogReader::tail()` with `LogFilter`, renders via
Symfony Console `Table` helper (already available through Castor's IO).

#### `castor log:search` — Search log entries

```bash
castor log:search "timeout" [--level=WARNING] [--from="-1 hour"] [--to="now"]
```

Searches across all log files, case-insensitive substring match on message
and stringified context. Renders same table format.

#### `castor log:files` — List log files

```bash
castor log:files
```

Lists `.hatfield/logs/` files with size, modified date, and entry count.

#### `castor log:clear` — Clear log files

```bash
castor log:clear [--older-than=7d]
```

Removes rotated log files older than N days. Keeps the current log file.

### 3.4 Castor task implementation approach

The Castor tasks can be implemented in two ways:

**Option A: PHP functions calling LogReader directly**  
The Castor tasks instantiate `LogParser` and `LogReader` directly (no container
needed). This is simpler and avoids booting the full kernel just to read logs.

**Option B: Symfony console commands**  
Create `app:log:tail`, `app:log:search`, etc. as Symfony console commands
that use DI-injected `LogReader`. Castor tasks delegate to these commands.

**Recommended: Option A** — Castor tasks create `LogParser`/`LogReader` directly.
This keeps log reading independent of the full DI container, works in CI and
debugging scenarios, and avoids booting the kernel for simple log inspection.

```php
// In .castor/tasks.php

function create_log_reader(): LogReader
{
    $logDir = getcwd() . '/.hatfield/logs';
    return new LogReader(new LogParser(), $logDir);
}

#[AsTask(name: 'log:tail', description: 'Show recent log entries')]
function log_tail(?string $level = null, int $lines = 50, ?string $search = null): void
{
    $reader = create_log_reader();
    $filter = new LogFilter(level: $level, search: $search, limit: $lines);
    $entries = $reader->tail($lines, $filter);
    render_log_table($entries);
}
```

---

## Phase 4: Additional logging points

### 4.1 Agent loop lifecycle

Log at key points in `RunCommit::commit()`:

```php
$this->logger?->info('run.commit.started', [
    'run_id' => $state->runId,
    'from_version' => $state->version,
]);
// ...
$this->logger?->info('run.commit.persisted', [
    'run_id' => $nextState->runId,
    'to_version' => $nextState->version,
    'events_count' => count($events),
]);
```

### 4.2 LLM provider calls

Log in `BeforeProviderRequestSubscriber`:

```php
$this->logger?->info('llm.provider.request', [
    'provider' => $providerName,
    'model' => $modelId,
    'run_id' => $context['run_id'] ?? null,
]);
```

### 4.3 Session lifecycle

Log session create/resume/destroy in `HatfieldSessionStore`.

---

## File summary

### New files

| File | Purpose |
|------|---------|
| `src/CodingAgent/Logging/HatfieldRotatingLogHandler.php` | Custom RotatingFileHandler with JsonFormatter, auto-mkdir |
| `src/CodingAgent/Logging/LogParser.php` | JSONL line parser → `LogEntry` value object |
| `src/CodingAgent/Logging/LogReader.php` | File scanner, streaming reader, tail implementation |
| `src/CodingAgent/Logging/LogEntry.php` | Immutable log entry value object |
| `src/CodingAgent/Logging/LogFilter.php` | Specification-pattern filter (level, search, date range) |
| `config/packages/monolog.yaml` | Monolog DI wiring |
| `tests/CodingAgent/Logging/LogParserTest.php` | Unit tests for JSONL parsing |
| `tests/CodingAgent/Logging/LogReaderTest.php` | Unit tests for file reading + filtering |
| `tests/CodingAgent/Logging/LogFilterTest.php` | Unit tests for filter matching |

### Modified files

| File | Change |
|------|--------|
| `composer.json` | Add `monolog/monolog` to `require` |
| `.castor/tasks.php` | Add `log:tail`, `log:search`, `log:files`, `log:clear` tasks |
| `src/CodingAgent/CLI/AgentCommand.php` | Inject `?LoggerInterface`, add top-level exception logging |
| `src/Tui/Application/InteractiveMode.php` | Inject `?LoggerInterface`, log silent catches |
| `src/Tui/Runtime/RuntimeEventPoller.php` | Inject `?LoggerInterface`, log silent catches |
| `src/AgentCore/Application/Pipeline/RunCommit.php` | Add structured log calls at commit lifecycle points |

### Not modified (already wired)

- `RunTracer` — already accepts `?LoggerInterface`, will auto-receive Monolog logger
- `.hatfield/.gitignore` — already contains `logs/` entry

---

## Execution order

1. **Phase 1** — Monolog setup (install, handler, DI config)
2. **Phase 2** — Exception logging (AgentCommand, InteractiveMode, RuntimeEventPoller)
3. **Phase 3** — Log reading infrastructure (LogEntry, LogParser, LogReader, LogFilter, tests)
4. **Phase 4** — Castor log tasks (tail, search, files, clear)
5. **Phase 5** — Additional logging points (agent loop, LLM calls, session lifecycle)

Phases 1–2 are the minimum viable logging. Phases 3–4 add the developer experience.
Phase 5 is incremental and can be done in follow-up tasks.

---

## Design decisions

| Decision | Rationale |
|----------|-----------|
| Monolog directly, no MonologBundle | No FrameworkBundle; custom DI wiring is straightforward |
| JSONL format only | We control the format; dual-format parsing is unnecessary complexity |
| RotatingFileHandler (daily) | Prevents unbounded log growth; 14-day retention is sensible |
| Non-nullable `LoggerInterface` injection | With the alias registered, autowiring always provides a real logger — no `?->` needed. Existing `?LoggerInterface` params in `RunTracer`/`RunCommit` should be made non-nullable.
| CWD-relative `.hatfield/logs/` | PHAR-safe; `getcwd()` returns the project directory when running from PHAR |
| Castor tasks use LogReader directly | No kernel boot needed for log inspection; simpler, works in CI |
| `LogFilter` specification pattern | Borrowed from `ai-monolog-mate-extension`; clean, testable, composable |
| No MCP tool exposure | The log tools are for developer CLI use, not for AI agent tooling (yet) |
