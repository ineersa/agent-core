# PHAR Packaging with humbug/box

## Goal
## Goal
Package agent-core as a single PHAR file using humbug/box, with a Castor build command and real validation against llama.cpp.

## Why
Single-binary distribution for the CLI agent. Eliminates `composer install` requirement on target machines. The codebase is ~70% PHAR-ready already (see scout analysis).

## Context
- humbug/box is installed globally (`box` command available)
- Symfony 8.1 console-only app, no HTTP stack
- `AppResourceLocator` and `app.cwd` (getcwd()) already handle PHAR-safe paths
- `.hatfield/` paths resolve against CWD, not PHAR location
- `ConsumerSupervisor` already uses `$_SERVER['argv'][0]` for entrypoint resolution
- Native extension `php-llama/llama-cpp-bindings` cannot be bundled (system requirement)
- `src/CodingAgent/Runtime/Process/AGENTS.md` documents planned `BinaryLocator` pattern

## What works inside PHAR already
- Container boot, config loading, service wiring
- `.hatfield/` paths (sessions, SQLite messenger, logs, cache)
- Theme/settings loading via AppResourceLocator
- `$_SERVER['argv'][0]` for child process spawning (ConsumerSupervisor)

## What needs fixing
### 1. Remaining `dirname(__DIR__, N)` references
Some files still use hardcoded path resolution instead of `$_SERVER['argv'][0]` or container params:
- `JsonlProcessAgentSessionClient.php` — `dirname(__DIR__, 4)`
- `AgentProcessSupervisor.php` — takes console path from caller
- Any remaining hardcoded paths in headless mode

### 2. Box configuration (box.json)
Create `box.json` with:
```json
{
    "directories": ["src", "config", "vendor"],
    "files": ["bin/console", ".env"],
    "output": "coding-agent.phar",
    "compression": "GZ",
    "algorithm": "SHA256",
    "main": "bin/console",
    "php-extensions": ["pdo_sqlite"],
    "exclude-composer-files": false,
    "force-heresies": true,
    "dev": false
}
```

Key flags:
- `force-heresies: true` — Symfony uses reflection for autowiring
- `exclude-composer-files: false` — AbstractKernel needs composer.json for getProjectDir()
- `dev: false` — exclude phpunit, phpstan, cs-fixer from PHAR

### 3. Castor command
- `castor phar:build` — runs `box compile` and reports output path + size

### 4. PHPUnit test (not a Castor command)
Create `tests/CodingAgent/Phar/PharSmokeTest.php` following the same pattern as `LlamaCppSmokeTest` and `TuiAgentSmokeTest`:
- `#[Group('llm-real')]` — same group as existing real tests, runs via `castor test:llm-real`
- Creates a temp directory in `./var/` with a fresh CWD for isolation
- Builds the PHAR (or expects pre-built)
- Launches PHAR in tmux: `php coding-agent.phar agent 2>&1`
- Sends a few prompts ("Say exactly: hello", "Say exactly: world")
- Waits for assistant responses in transcript
- Takes TUI snapshots
- Asserts:
  - At least 2 user blocks (❯) and 2 assistant blocks (◇) in correct order
  - `.hatfield/sessions/*/events.jsonl` exists and is non-empty
  - `.hatfield/sessions/*/state.json` exists and contains expected status
  - `.hatfield/sessions/*/transcript.jsonl` exists and is non-empty
  - `.hatfield/messenger.sqlite` exists
  - All paths are relative to temp CWD, not inside PHAR
- Dumps snapshots + session artifacts to stdout on failure
- Cleans up temp directory in tearDown

## Acceptance criteria
- [ ] `box.json` committed with correct Symfony settings
- [ ] `castor phar:build` generates working `coding-agent.phar`
- [ ] PHAR boots container and responds to `php coding-agent.phar list`
- [ ] PHAR launches TUI in tmux via PHPUnit test
- [ ] Test sends multiple prompts, verifies responses in transcript
- [ ] TUI snapshots captured on failure
- [ ] Session artifacts verified at correct CWD-relative paths (not inside PHAR)
- [ ] `.hatfield/messenger.sqlite` exists relative to CWD
- [ ] Temp directory in `./var/` created and cleaned up
- [ ] All hardcoded `dirname(__DIR__, N)` paths eliminated or PHAR-safe
- [ ] PHAR size is reasonable (< 20MB compressed)
- [ ] `castor check` passes on source code

## Acceptance criteria
- box.json committed with force-heresies + exclude-composer-files:false
- castor phar:build generates coding-agent.phar
- PHAR boots: php coding-agent.phar list shows commands
- PHPUnit test #[Group('phar-real')] builds or expects PHAR, runs in tmux
- Test sends multiple prompts, verifies ❯ and ◇ blocks in transcript
- TUI snapshots captured and dumped on failure
- Session artifacts created at CWD/.hatfield/sessions/ (not inside PHAR)
- messenger.sqlite created at CWD/.hatfield/messenger.sqlite
- Temp dir created in ./var/ and cleaned up after test
- No hardcoded dirname(__DIR__, N) paths remain in runtime/controller/process code
- PHAR < 20MB compressed
- castor check passes

## Workflow metadata
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-22T18:43:48.232Z
