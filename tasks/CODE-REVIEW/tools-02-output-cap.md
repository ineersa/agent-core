# TOOLS-02 Implement OutputCap service for large tool output

## Goal
Implement reusable output capping and persistence for text-producing tools.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Scope:
- Create `src/CodingAgent/Tool/OutputCap.php`.
- Follow the user's pi output-cap extension behavior with constructor/configurable defaults so TOOLS-R04 can wire values from Hatfield settings:
  - code/default cap: 20,000 chars (~5k tokens)
  - docs cap: 50,000 chars for doc-like files (`.md`, `.txt`, `.toon` at minimum)
  - stale file max age: 24 hours
- Persist oversized full output under `.hatfield/tmp/output-cap/<session-prefix>-<random-hex>.txt`.
- Return either unchanged text or a capped notice containing char count, rough token estimate, saved path, and `head`/`grep` hint.
- Add cleanup for stale files older than 24h. If no explicit session-start hook exists yet, expose a public cleanup method and call it from service construction or first use; document the choice in code comments.
- Add focused PHPUnit tests.

Out of scope:
- Do not implement read/bash tools here.
- Do not implement `.hatfield` settings here; expose constructor/configuration inputs with safe defaults. TOOLS-R04 owns hydrating these values from Hatfield settings.

## Acceptance criteria
- `OutputCap` can cap/persist oversized text and return a model-facing notice with saved path and inspection hints.
- Small output is returned unchanged.
- Doc-like paths use the configurable doc cap defaulting to 50,000 chars; other paths use the configurable default cap defaulting to 20,000 chars.
- Saved files are written under `.hatfield/tmp/output-cap/` and parent directories are created as needed.
- Cleanup deletes files older than the configurable retention defaulting to 24 hours and leaves newer files intact.
- Focused tests pass with Castor/PHPUnit.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/tools-02-output-cap
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-02-output-cap
Fork run: k2mqiwwnvqqs
PR URL: https://github.com/ineersa/agent-core/pull/56
PR Status: open
Started: 2026-05-26T16:08:52.732Z
Completed:

## Work log
- Created: 2026-05-17T04:42:04.932Z

## Task workflow update - 2026-05-26T16:08:52.732Z
- Moved TODO → IN-PROGRESS.
- Created branch task/tools-02-output-cap.
- Created worktree /home/ineersa/projects/agent-core-worktrees/tools-02-output-cap.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/tools-02-output-cap.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/tools-02-output-cap.
- Summary: Started as part of wave 1 tools foundation per toolbox design plan.

## Task workflow update - 2026-05-26T16:33:32.494Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/tools-02-output-cap to origin.
- branch 'task/tools-02-output-cap' set up to track 'origin/task/tools-02-output-cap'.
- Created PR: https://github.com/ineersa/agent-core/pull/56
- Validation: castor test --filter=OutputCap: pass (21 tests, 36 assertions); castor deptrac: pass (0 violations, 0 errors); castor cs-check --path src/CodingAgent/Tool/OutputCap.php: pass; castor cs-check --path tests/CodingAgent/Tool/OutputCapTest.php: pass
- Summary: Implemented OutputCap service in src/CodingAgent/Tool/OutputCap.php with configurable default/doc caps, persisted oversized output under .hatfield/tmp/output-cap, capped notice with char/token count and head/grep hints, stale cleanup on first use, public persist() for future bash integration, and focused tests in tests/CodingAgent/Tool/OutputCapTest.php. Committed as 31ddb970 on task/tools-02-output-cap.

## Task workflow update - 2026-05-26T16:42:42.296Z
- Summary: Reviewer subagent result: REQUEST CHANGES. Critical finding: OutputCap default storage path concatenates getcwd() without checking false, which can become /.hatfield/tmp/output-cap. Additional bugs: testDefaultStorageDirUsesCwdDotHatfield leaks files into real project .hatfield/tmp/output-cap; persist() does not check file_put_contents result; cleanup-on-first-use only runs from process(), not public persist(); filename format lacks session-prefix support required by task/plan. Review artifact: /home/ineersa/.pi/agent/tmp/2026-05--aa7a555a.txt

## Task workflow update - 2026-05-26T19:05:35.739Z
- Recorded fork run: k2mqiwwnvqqs
- Summary: Launched follow-up fork to address PR #56 reviewer issues and inline comments. Comments require OutputCap defaults to come from AppConfig/settings rather than getcwd/constructor literals, and to stop using 0777 permissions. Fork will fix prior reviewer issues too: getcwd false/root path risk, project .hatfield test leakage, unchecked file_put_contents, cleanup not running for direct persist(), session-prefix filename support, restrictive mkdir/write failure handling, settings/docs/defaults updates, focused tests and Castor validation.

## Task workflow update - 2026-05-26T19:10:46.589Z
- Recorded fork run: k2mqiwwnvqqs
- Validation: castor test --filter=OutputCap: pass (29 tests, 54 assertions); castor deptrac: pass (0 violations, 0 errors); castor cs-check --path src/CodingAgent/: pass after cs-fix; castor test: pass (983 tests, 10037 assertions)
- Summary: Follow-up fork completed despite signal_aborted notification and pushed PR #56 branch update. Commit 1c894c79 adds OutputCapConfig and settings-backed tools.output_cap config, uses AppConfig/settings for defaults instead of raw getcwd/hardcoded constructor defaults, resolves tools.output_cap.path via AppConfigLoader, uses 0750 mkdir with explicit failure handling, checks file_put_contents result, runs cleanup from persist(), adds session-prefix filename support, removes test leakage into project .hatfield, updates docs/settings.md, config/hatfield.defaults.yaml, .hatfield/settings.yaml, services.yaml, and deptrac AppTool dependencies.
