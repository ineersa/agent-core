# SAFE-01 SafeGuard policy store and classifier

## Goal

Faithful PHP port of the Pi safe-guard extension's policy and classifier logic from
`/home/ineersa/claw/my-pi/packages/extensions/extensions/safe-guard/`.
This task builds the classifier and policy loading only — no tool execution, no hooks, no extension wiring.

Reference files:
- `classify.ts` — bash command classification with regex patterns
- `policy.ts` — policy loading, merging, path/command matching, mutations
- `safe-guard.ts` — tool call interception (read/write/edit/bash logic)

Depends on: none strictly.

## Scope

- Add SafeGuard policy model, policy store, path matcher, command matcher, and classifier.
- Read policy from Hatfield locations: project `<cwd>/.hatfield/safe-guard.json` and home `~/.hatfield/safe-guard.json`.
- Keep default protected read and dangerous command patterns active when no policy file exists.
- Model hard blocks separately from policy-relaxable rules.

## Pi behavior to faithfully port

### Policy fields (from policy.ts SafeGuardPolicy interface)
```json
{
  "allowCommandPatterns": [],
  "allowWriteOutsideCwd": [],
  "allowDestructiveInPaths": [],
  "protectedReadPatterns": [],
  "dangerousCommandPatterns": []
}
```
- `allowDestructiveInPaths` is kept for compatibility but NOT wired to any logic (Pi never checks it).

### Policy loading (from policy.ts loadPolicy)
- Project-local `<cwd>/.hatfield/safe-guard.json` takes precedence entirely (replaces global).
- If no local file, fall back to global `~/.hatfield/safe-guard.json`.
- If neither exists, use built-in defaults.
- `protectedReadPatterns` is ALWAYS additive: built-in defaults + file-specified patterns.
- Invalid/unreadable JSON files are silently ignored (return null, fall through).

### Bash classification (from classify.ts)
- Kinds: `block` (hard, sudo), `destructive`, `dangerousGit`, `sensitiveInfo`, `customDangerous`, `allow`.
- Hard block regex: `/\bsudo\b/` — never allowlisted, never asked.
- Destructive regexes: `rm`, `rmdir`, `git clean`, `git reset --hard`, `git checkout -- .`, `mkfs`, `dd if=`, `chmod [0-7]{3,4}`, `chown -[rR]`, `mv ... /dev/null`.
- Dangerous git regexes: `git push (-f|--force)`, `git branch -[dD]`, `git tag -d`, `git rebase`, `git reflog expire`.
- Sensitive info regexes: `^\s*env\b`, `^\s*printenv\b`, `env\s*\|`, `printenv\s*\|`.
- Custom dangerous: substring match (normalized lowercase) against `dangerousCommandPatterns`.
- Command allowlist check runs BEFORE classification (substring match on normalized command).

### Write/edit outside CWD (from safe-guard.ts)
- `isInsideCwd(cwd, path)`: `resolve(cwd, path)` starts with `resolve(cwd) + '/'` or equals it exactly.
- `isPathInList(list, path)`: resolved path equals or starts with `resolve(entry) + '/'`.
- Strip leading `@` from paths before checking.

### Protected reads (from policy.ts isProtectedReadPath)
- Match by: exact basename, path ends-with pattern, path contains pattern as segment.
- Default patterns (case-insensitive):
  `.env.local`, `.env.dev.local`, `.env.prod.local`, `.env.staging.local`, `.env.test.local`,
  `auth.json`, `credentials.json`, `.netrc`, `.npmrc`,
  `.bashrc`, `.zshrc`, `.bash_profile`, `.zprofile`, `.profile`, `.bash_history`, `.zsh_history`,
  `.ssh/id_`, `.ssh/config`, `.ssh/known_hosts`,
  `.aws/credentials`, `.aws/config`, `.kube/config`, `.gcp/`, `.config/gcloud/`, `.azure/`,
  `.pem`, `.pkcs12`, `.p12`, `.pfx`, `service-account`.

## Acceptance criteria
- Classifier hard-blocks `sudo` commands regardless of policy.
- Classifier identifies destructive commands: `rm`, `rmdir`, `git clean`, `git reset --hard`, `git checkout -- .`, `mkfs`, `dd if=`, `chmod 777`, `chown -R`, `mv ... /dev/null`.
- Classifier identifies dangerous git: `git push --force`, `git push -f`, `git branch -d/-D`, `git tag -d`, `git rebase`, `git reflog expire`.
- Classifier identifies sensitive info: `env`, `printenv`, `env |`, `printenv |`.
- Classifier identifies custom dangerous via substring match on `dangerousCommandPatterns`.
- Classifier identifies writes/edits outside CWD by resolving target paths against CWD.
- Classifier identifies protected reads using the full Pi default pattern list.
- Command allowlist bypasses destructive/dangerous/sensitive checks via substring match.
- Write path allowlist bypasses outside-CWD checks via prefix path match.
- Policy loading: project-local replaces global; protectedReadPatterns always additive on defaults.
- Invalid policy JSON is silently ignored (falls through to next source).
- `allowDestructiveInPaths` field exists in policy model but is not wired to any logic.
- Unit tests cover all classifier behavior without invoking real tools.
- Validation with Castor: `castor test --filter SafeGuard`; `castor deptrac`.

## Workflow metadata
Status: DONE
Branch: task/safe-01-safeguard-policy-classifier
Worktree: /home/ineersa/projects/agent-core-worktrees/safe-01-safeguard-policy-classifier
Fork run:
PR URL: https://github.com/ineersa/agent-core/pull/68
PR Status: merged
Started: 2026-05-29T22:58:17.134Z
Completed: 2026-05-30T00:48:42.350Z

## Work log
- Created: 2026-05-29T20:50:06.010Z

## Task workflow update - 2026-05-29T22:58:17.134Z
- Moved TODO → IN-PROGRESS.
- Created branch task/safe-01-safeguard-policy-classifier.
- Created worktree /home/ineersa/projects/agent-core-worktrees/safe-01-safeguard-policy-classifier.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/safe-01-safeguard-policy-classifier.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/safe-01-safeguard-policy-classifier.

## Task workflow update - 2026-05-29T23:06:08.609Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/safe-01-safeguard-policy-classifier to origin.
- branch 'task/safe-01-safeguard-policy-classifier' set up to track 'origin/task/safe-01-safeguard-policy-classifier'.
- Created PR: https://github.com/ineersa/agent-core/pull/68

## Task workflow update - 2026-05-30T00:48:42.350Z
- Moved CODE-REVIEW → DONE.
- Merged task/safe-01-safeguard-policy-classifier into integration checkout.
- Merge made by the 'ort' strategy.
 config/hatfield.defaults.yaml                      |  54 +++-
 config/services.yaml                               |   4 +-
 depfile.yaml                                       |  12 +-
 src/CodingAgent/Config/ExtensionsConfig.php        |  11 +-
 .../SafeGuard/Classifier/SafeGuardClassifier.php   | 206 +++++++++++++
 .../Classifier/SafeGuardCommandMatcher.php         | 165 +++++++++++
 .../SafeGuard/Classifier/SafeGuardPathMatcher.php  | 113 ++++++++
 .../Builtin/SafeGuard/Policy/SafeGuardDecision.php |  57 ++++
 .../SafeGuard/Policy/SafeGuardDecisionKind.php     |  30 ++
 .../Builtin/SafeGuard/Policy/SafeGuardPolicy.php   |  52 ++++
 .../Builtin/SafeGuard/SafeGuardConfig.php          | 158 ++++++++++
 src/CodingAgent/Extension/ExtensionApiBridge.php   |  10 +
 .../Extension/ExtensionToolRegistryBridge.php      |  14 +
 .../ExtensionApi/ExtensionApiInterface.php         |  18 ++
 .../Classifier/SafeGuardClassifierTest.php         | 264 +++++++++++++++++
 .../Classifier/SafeGuardCommandMatcherTest.php     | 320 +++++++++++++++++++++
 .../Classifier/SafeGuardPathMatcherTest.php        | 232 +++++++++++++++
 .../SafeGuard/Policy/SafeGuardPolicyTest.php       |  85 ++++++
 .../Builtin/SafeGuard/SafeGuardConfigTest.php      | 136 +++++++++
 .../Extension/ExtensionToolRegistryBridgeTest.php  |  71 ++++-
 .../ExtensionApi/ExtensionApiContractsTest.php     |  37 +++
 21 files changed, 2035 insertions(+), 14 deletions(-)
 create mode 100644 src/CodingAgent/Extension/Builtin/SafeGuard/Classifier/SafeGuardClassifier.php
 create mode 100644 src/CodingAgent/Extension/Builtin/SafeGuard/Classifier/SafeGuardCommandMatcher.php
 create mode 100644 src/CodingAgent/Extension/Builtin/SafeGuard/Classifier/SafeGuardPathMatcher.php
 create mode 100644 src/CodingAgent/Extension/Builtin/SafeGuard/Policy/SafeGuardDecision.php
 create mode 100644 src/CodingAgent/Extension/Builtin/SafeGuard/Policy/SafeGuardDecisionKind.php
 create mode 100644 src/CodingAgent/Extension/Builtin/SafeGuard/Policy/SafeGuardPolicy.php
 create mode 100644 src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardConfig.php
 create mode 100644 tests/CodingAgent/Extension/Builtin/SafeGuard/Classifier/SafeGuardClassifierTest.php
 create mode 100644 tests/CodingAgent/Extension/Builtin/SafeGuard/Classifier/SafeGuardCommandMatcherTest.php
 create mode 100644 tests/CodingAgent/Extension/Builtin/SafeGuard/Classifier/SafeGuardPathMatcherTest.php
 create mode 100644 tests/CodingAgent/Extension/Builtin/SafeGuard/Policy/SafeGuardPolicyTest.php
 create mode 100644 tests/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardConfigTest.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/safe-01-safeguard-policy-classifier.
- Pulled integration checkout: Merge made by the 'ort' strategy..
