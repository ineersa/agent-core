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
Fork run: iacc0j9g85c1
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

## Task workflow update - 2026-05-26T19:25:04.439Z
- Validation: Reviewer comments to address: config/hatfield.defaults.yaml line 90: pull main, use PathResolver instead of manual resolution; src/CodingAgent/Config/OutputCapConfig.php line 53: rework AppConfig as DTO and use Symfony serializer instead of raw array guards; prior comments: OutputCap must use AppConfig/settings, defaults from settings, no 0777 permissions.
- Summary: Preparing second PR #56 follow-up after new reviewer comments. Required direction: pull/merge main so TOOLS-01 PathResolver is available; replace manual config-array guard code in OutputCapConfig/AppConfig path with a typed AppConfig DTO hydrated through Symfony Serializer/Denormalizer; use AppConfig/settings and PathResolver/SettingsPathResolver path resolution rather than getcwd/manual fallback; keep storage perms restrictive and fail loudly on write/create errors.

## Task workflow update - 2026-05-26T19:26:06.638Z
- Summary: Reviewer/user clarification after fork launch: prefer config DTOs instead of raw. AppConfig::raw should not be the path for known configuration. OutputCap, LoggingConfig, ExtensionManager, HatfieldSessionStore, and other known config consumers should use typed DTO properties. If any raw compatibility remains, it must not be used for known app sections or TOOLS-02 behavior.

## Task workflow update - 2026-05-26T19:36:36.040Z
- Recorded fork run: iacc0j9g85c1
- Validation: Fork reported: git fetch origin && git merge origin/main to pick up PathResolver; Fork reported: castor test --filter='OutputCap|AppConfigLoader|SettingsPathResolver|HatfieldSessionStore|ExtensionManager' => 80 tests, 185 assertions, 0 failures; Fork reported: castor test => 1018 tests, 10078 assertions, 0 failures; Fork reported: castor deptrac => 0 violations, 0 errors; Fork reported: castor cs-check => 0 failures; Parent spot-check: branch task/tools-02-output-cap HEAD/origin at 8a7cd85e; git status clean; source grep confirms known production consumers use typed config DTOs for OutputCap/session/extensions, with AppConfig::raw retained only in AppConfig and tests/AI helpers.
- Summary: Follow-up fork completed and pushed commit 8a7cd85e to PR #56. Reworked OutputCap config through typed DTO hydration: added SessionsConfig, ExtensionsConfig, ToolsConfig; AppConfig::fromContainer now denormalizes known sections via Symfony Serializer; OutputCap requires OutputCapConfig and removes null/constructor override fallbacks; OutputCapConfig::fromAppConfig returns appConfig->tools->outputCap; LoggingConfig::fromAppConfig returns appConfig->logging; ExtensionManager and HatfieldSessionStore use typed DTO properties; SettingsPathResolver delegates final path normalization to PathResolver. AppConfig::raw remains only as forward-compat storage for unknown keys; known production consumers should not use it.

## Task workflow update - 2026-05-26T22:49:44.864Z
- Validation: castor test --filter=OutputCapTest: ok (tests=28, assertions=52, errors=0, failures=0, skipped=0); castor cs-check: ok (files_fixed=0); castor deptrac: ok (violations=0, errors=0, uncovered=357, allowed=760)
- Summary: Addressed final PR #56 review comment by removing duplicated scalar config properties from OutputCap; OutputCap now reads storage path, caps, retention, and session prefix directly from the injected OutputCapConfig DTO.
