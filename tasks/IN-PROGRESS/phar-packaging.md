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
Status: IN-PROGRESS
Branch: task/phar-packaging
Worktree: /home/ineersa/projects/agent-core-worktrees/phar-packaging
Fork run:
PR URL:
PR Status:
Started: 2026-06-04T18:43:54.659Z
Completed:

## Work log
- Created: 2026-05-22T18:43:48.232Z

## Task workflow update - 2026-06-04T17:49:28.833Z
- PHAR readiness scout recon completed (3 scouts). Key findings: no Box/PHAR tooling exists yet; Kernel/bin entrypoint/cache/log/config paths are the first PHAR blockers; runtime process abstraction exists but only SourceTreeExecutableLocator is wired; AgentProcessSupervisor and Castor run/log tasks still hardcode bin/console; Controller/TUI E2E tests hardcode bin/console and should use a PHAR built to a stable /tmp/bin path. Scout artifact: /home/ineersa/.pi/agent/tmp/2026-06--3741ac4e.txt

## Task workflow update - 2026-06-04T18:41:13.395Z
- Design decision captured before implementation: PHAR/source app root must be treated as read-only/install root. Runtime writable paths should resolve from the runtime cwd, not kernel.project_dir. Defaults should use existing project-local Hatfield runtime tree: logs at <cwd>/.hatfield/logs, cache at <cwd>/.hatfield/cache, tmp at <cwd>/.hatfield/tmp as needed. Optional env overrides may exist for unusual deployments, but should be resolved relative to runtime cwd when relative and should not be required. This avoids PHAR/source-tree contamination and aligns tests/runs around isolated cwd behavior.

## Task workflow update - 2026-06-04T18:43:06.454Z
- Open PHAR design questions resolved: build output should be the stable PHAR file `/tmp/bin/hatfield.phar` for now, leaving an unqualified `/tmp/bin/hatfield` wrapper/binary name available later. Writable directory override env vars should use the Hatfield namespace (`HATFIELD_LOG_DIR`, `HATFIELD_CACHE_DIR`, and analogous names if tmp/session overrides are needed), with relative override values resolved against runtime cwd. PHAR execution should be mandatory for CLI/controller/TUI subprocess tests and Castor run flows; pure in-process/unit tests may continue to run source code unless implementation discovers a strong reason to route them through PHAR too.

## Task workflow update - 2026-06-04T18:43:54.659Z
- Moved TODO → IN-PROGRESS.
- Created branch task/phar-packaging.
- Created worktree /home/ineersa/projects/agent-core-worktrees/phar-packaging.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/phar-packaging.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/phar-packaging.

## Task workflow update - 2026-06-04T18:48:18.541Z
- Task-start preparation complete. Loaded task-workflow/testing/castor skills, claimed task to branch `task/phar-packaging` with worktree `/home/ineersa/projects/agent-core-worktrees/phar-packaging`, reread the task, and launched focused scout prep in the worktree. Additional scout artifact: `/home/ineersa/.pi/agent/tmp/2026-06--5a82d8af.txt`. Fork implementation instructions will use resolved decisions: `/tmp/bin/hatfield.phar`, HATFIELD_* writable-dir env overrides, runtime-cwd `.hatfield/logs` and `.hatfield/cache`, and mandatory PHAR use for CLI/controller/TUI subprocess flows.
