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
Fork run: 87swgqa7p0ti
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

## Task workflow update - 2026-06-04T18:49:54.895Z
- Recorded fork run: 8nxese4ug8s1
- Implementation fork launched in worktree `/home/ineersa/projects/agent-core-worktrees/phar-packaging` as run `8nxese4ug8s1`. Fork instructions cover Box config, runtime-cwd writable dirs, PHAR executable locator/wiring, Castor `phar:build`/`phar:ensure`, mandatory PHAR usage for Castor run/controller/TUI subprocess tests, focused validation, and commit requirements.

## Task workflow update - 2026-06-04T19:44:03.399Z
- Validation: Fork 8nxese4ug8s1 reported: php -l passed on changed files; box compile produced ~14.65MB PHAR with ~9153 files; `php /tmp/bin/hatfield.phar list` failed with ContainerBuilder missing service error; castor phpstan/cs-check/test not run.
- Summary: Fork `8nxese4ug8s1` completed partial implementation and committed `11680c1b` (`Implement PHAR packaging flow`). Scope implemented: box.json, runtime-cwd cache/log dirs, PHAR/config/source executable locators, Castor phar tasks, Castor run/test PHAR wiring, test executable helper, and PHAR smoke test. Current blockers: `/tmp/bin/hatfield.phar` build takes about 3+ minutes and PHAR smoke boot currently fails/stale build reports Symfony service-not-found for `ConfiguredSymfonyAiPlatformFactory`; build-time optimization is now the priority before continuing boot/debug work. Researcher found likely causes/speedups: current config includes all `vendor/` (about 9k files), Box config `exclude-dev-files` alone did not prevent dev packages in copied vendor from being included, and fastest path is to use Box finder/exclusion or prepare a production vendor/staging build so dev packages (phpstan/phpunit/cs-fixer/deptrac/jetbrains stubs) are excluded, plus disable optional slow features where safe (`dump-autoload`, `check-requirements`, no compactors, possibly dev/no-compression mode).

## Task workflow update - 2026-06-04T20:00:21.882Z
- Recorded fork run: 35m0818sv7jc
- Continuation fork launched as `35m0818sv7jc` in `/home/ineersa/projects/agent-core-worktrees/phar-packaging` to optimize PHAR build time before further boot debugging. Instructions: implement `/tmp` production staging build, run Composer `--no-dev` in staging, run Box from staging, add valid Box speed options, preserve `/tmp/bin/hatfield.phar`, report timings/file counts/vendor exclusions, and commit changes.

## Task workflow update - 2026-06-04T20:07:39.640Z
- Recorded fork run: 35m0818sv7jc
- Validation: Verified worktree log contains `66f45aeb Optimize PHAR build with production staging`; worktree status clean.; Verified commit stat: 2 files changed, 176 insertions, 16 deletions (`.castor/helpers.php`, `box.json`).; Fork validation reported: `php -l .castor/helpers.php` OK; helper runtime require OK; `castor phar:clean` OK; `castor phar:build` OK in ~33.7s; `castor phar:ensure` fresh path instant; stale detection triggered after touching `Kernel.php` and `box.json`; staged/PHAR vendor excludes dev packages; PHAR `list` boot smoke fails with separate `SessionRunStore` service-not-found error.
- Summary: Continuation fork `35m0818sv7jc` completed and committed `66f45aeb` (`Optimize PHAR build with production staging`). Build-time optimization is complete: `castor phar:build` now stages packaging input under `/tmp/hatfield-phar-build/source`, installs production Composer dependencies with `--no-dev`, runs Box from staging, keeps output `/tmp/bin/hatfield.phar`, adds Box speed options (`dump-autoload:false`, `check-requirements:false`), and adds mtime-based `phar:ensure` stale detection. Reported result: build time ~33.7s total (copy 0.0s, composer 1.2s, Box 32.5s), PHAR 5.9MB GZ, 5,146 files, dev packages excluded. Worktree verified clean after commit; latest commit changes `.castor/helpers.php` and `box.json` only. Remaining blocker: PHAR boot still fails separately with Symfony DI service error for `Ineersa\CodingAgent\Session\SessionRunStore`; next implementation should focus on PHAR boot/container compilation, not build speed.

## Task workflow update - 2026-06-04T20:15:10.042Z
- Additional PHAR env design decision: do not bundle `.env`; Kernel-level `APP_ENV`/`APP_DEBUG` must come from the process environment before Hatfield settings load. The PHAR artifact is built from production dependencies (`composer install --no-dev --optimize-autoloader` in staging), but runtime env should remain overridable: default `APP_ENV` can be `prod` when unset, while tests/Castor may set `APP_ENV=test` where appropriate; `APP_DEBUG` should not be forcibly disabled because it is useful for exception traces during Castor/test debugging. Hatfield settings continue to own app-level logging/model behavior, not Kernel environment selection.

## Task workflow update - 2026-06-04T20:16:21.724Z
- Recorded fork run: 0w078vuuyvan
- Continuation fork launched as `0w078vuuyvan` in `/home/ineersa/projects/agent-core-worktrees/phar-packaging` to adjust PHAR build/runtime env and debug boot. Instructions: remove `--classmap-authoritative`, preserve caller-controlled `APP_ENV`/`APP_DEBUG` with optional PHAR default `APP_ENV=prod`, do not bundle `.env`, rebuild via Castor, investigate the `SessionRunStore` missing service root cause, implement minimal robust boot fix, validate PHAR `list`, and commit changes.

## Task workflow update - 2026-06-04T20:43:59.033Z
- Recorded fork run: 0w078vuuyvan
- Validation: Verified commit exists: `8a919674 Fix PHAR boot: resource path wildcards, APP_ENV/autoloader fixes`.; Verified worktree status clean after fork commit.; Verified commit stat: 3 files changed, 17 insertions, 9 deletions (`.castor/helpers.php`, `bin/console`, `config/services.yaml`).; Fork validation reported: `php -l .castor/helpers.php` OK; helper require OK; `php -l bin/console` OK; full PHAR build via helper OK (~33.6s, 5.9MB); `php /tmp/bin/hatfield.phar list` OK with all commands listed including `agent`; `php /tmp/bin/hatfield.phar about` OK (`Environment: prod`, `Debug: false`); `castor phar:build` OK; `castor phar:ensure` fresh path OK (~7ms).; Fork intentionally did not run full `castor check` or E2E tests; `APP_ENV=test` PHAR execution fails as expected because test-only bundle/dependencies are absent from production vendor.
- Summary: Continuation fork `0w078vuuyvan` completed and committed `8a919674` (`Fix PHAR boot: resource path wildcards, APP_ENV/autoloader fixes`). PHAR now boots successfully. Root causes fixed: Symfony DI `resource:` imports using bare directory paths do not register services reliably inside `phar://`, so `config/services.yaml` resource/exclude paths were changed to `%kernel.project_dir%/.../**/*.php` wildcard patterns; `bin/console` now defaults unset `APP_ENV` to `prod`; staging Composer install removed `--classmap-authoritative` and build/smoke commands set default `APP_ENV=prod` while preserving caller-controlled `APP_DEBUG`. Verified worktree clean after commit. Latest commit changes `.castor/helpers.php`, `bin/console`, and `config/services.yaml` with 17 insertions / 9 deletions. Remaining work before PR: run selected Castor validation/reviewer in the next task-to-pr phase per workflow; no full validation was run during implementation phase.

## Task workflow update - 2026-06-04T20:46:59.246Z
- Recorded fork run: 87swgqa7p0ti
- Validation/fix fork launched as `87swgqa7p0ti` in `/home/ineersa/projects/agent-core-worktrees/phar-packaging` to verify PHAR subprocess test behavior and Castor validation. Instructions: inspect PHAR test/Castor wiring; verify tests execute `/tmp/bin/hatfield.phar` from isolated `var/tmp/test-*` cwd; verify `.hatfield/settings.yaml`, logs/cache/tmp/session artifacts are created under isolated cwd; run Castor-focused validation and full `castor check` if prerequisites are available; implement/commit minimal fixes only if PHAR/test isolation issues are found; do not push, PR, move tasks, or run reviewer.
