---
description: Simplify scoped code through parallel reuse, quality, and efficiency review, then apply the smallest safe cleanup
argument-hint: "[scope]"
---

# Simplify

Review and simplify the requested scope without changing intended behavior or inventing new requirements.

Requested scope: `$ARGUMENTS`

## Priorities

Apply these in order:

1. Correctness, security, data integrity, and finalized requirements.
2. Preserve externally visible behavior and stable contracts unless the scope explicitly asks to change them.
3. Reuse an existing project abstraction when it already owns the concept.
4. Otherwise prefer PHP standard-library, Symfony—including DI, Console, Messenger, Serializer, Lock, EventDispatcher/Events, and Symfony TUI—Doctrine, or installed-package functionality over custom infrastructure.
5. Prefer KISS and YAGNI. Use DRY only for a genuine shared concept, not incidental similarity.
6. Apply SOLID only when it simplifies an existing boundary; never use it to justify speculative interfaces, factories, decorators, configuration, or extension points.
7. Produce the smallest correct diff with the fewest files and least new code. Do not replace clear code with compressed or clever code.

Repository and nested `AGENTS.md` instructions remain authoritative. Do not create a task, report file, branch, commit, PR, or persistent review artifact unless the user or a higher-priority workflow explicitly requires it.

## Phase 1: Resolve the scope

Inspect repository instructions and relevant architecture/configuration files before reviewing.

Treat non-empty `$ARGUMENTS` as authoritative:

- If it identifies a Git revision or range, review that complete diff.
- If it identifies files or directories, review their current implementation, using current changes as the primary focus when present.
- If it is a natural-language scope, resolve the smallest matching set of files and do not broaden it merely because adjacent code could also be improved.
- Never interpolate raw `$ARGUMENTS` directly into a shell command.

If the supplied scope is ambiguous, resolves to materially different targets, or cannot be found, ask the user to clarify instead of guessing. If it resolves unambiguously but contains no relevant files or changes, stop and report that.

When `$ARGUMENTS` is empty:

1. Inspect `git status --short`.
2. If the worktree has staged or unstaged tracked changes, review the complete tracked diff against `HEAD`.
3. Include every untracked file reported by `git ls-files --others --exclude-standard`; Git diff does not include their contents.
4. If the worktree is clean, review the latest commit, including all files changed by it. Handle an initial/root commit correctly.

Create a concise scope packet containing:

- checkout/worktree path;
- target files;
- Git baseline or revision range, when applicable;
- relevant requirements and invariants;
- commands scouts can use to inspect the complete scope.

Do not review only selected hunks. Review changed code together with enough surrounding code, callers, tests, configuration, and architecture rules to judge it correctly.

If the scope includes tests, test strategy, TUI/runtime/Messenger/DB behavior, or requires QA, load the testing skill and read `tests/AGENTS.md` before launching subagents. Require every relevant scout or implementation fork to do the same and state that it followed both in its handoff.

Resolve the exact checkout before launching subagents. If the subagent tool supports a per-task `cwd`, set it to that checkout. Otherwise verify that the runtime's current working directory is already the exact checkout. When a tracked task identifies a dedicated worktree, use it; otherwise use the current checkout. Do not create or switch worktrees merely for this review. If the available subagent runtime cannot inspect the exact checkout safely, stop and report the limitation.

## Phase 2: Run proportional independent audits

Choose scouts proportionally: use none when the main agent already owns sufficient context; one read-only scout for an unfamiliar bounded scope; and parallel scouts only when genuinely independent lenses materially reduce risk in cross-module, security-sensitive, or architecturally ambiguous work. Never re-scout research already completed by the main agent. When investigation should retain context into implementation, assign one fork the complete investigation-plus-implementation slice instead.

For every chosen scout, provide the resolved scope packet, applicable priorities/invariants, a distinct lens where multiple scouts are used, and require complete-scope evidence. Batch independent scouts in one parallel `subagent` call; do not impose a scout count. Scouts gather evidence only; the main agent verifies and classifies findings.

Ask each scout to:

- make no file changes;
- report only actionable, code-backed findings;
- cite exact files and lines or symbols;
- explain the concrete cost or risk;
- propose the smallest safe fix;
- state the behavior, contract, or invariant that must remain unchanged;
- avoid style-only preferences, speculative abstractions, broad redesigns, and unrelated pre-existing issues;
- report correctness, security, or data-integrity defects encountered inside the resolved scope even when they are not primarily simplification findings.

Request this finding format where compatible with the scout's required handoff format:

```text
[ID] <category> — <file:line or symbol>
Problem: <specific issue and evidence>
Minimal fix: <smallest safe change>
Preserve: <behavior, contract, or invariant>
```

### Scout 1: Reuse and architecture

Review for unnecessary invention and misplaced responsibility:

- Search existing project services, helpers, DTOs, value objects, enums, repositories, mappers, factories, tests, and adjacent implementations before proposing new code.
- Search PHP standard-library functions, Symfony components—including DI, Console, Messenger, Serializer, Lock, EventDispatcher/Events, and Symfony TUI—Doctrine facilities, and installed dependencies that already solve the problem.
- Flag duplicated functionality only when semantics and ownership match. Similar syntax alone is not a reason to abstract.
- Respect `depfile.yaml` and existing module boundaries. Do not move responsibility across layers merely to reduce line count.
- Prefer existing Symfony and Doctrine patterns when they fit; do not introduce framework ceremony for clear local logic.
- Flag manual service locators, string routers, payload walkers, avoidable `instanceof` chains, and associative-array contracts crossing boundaries when an existing typed mechanism fits.
- Local short-lived arrays are fine; do not demand a DTO without a stable boundary or invariant.
- Flag pass-through methods or classes only when they add no invariant, semantic boundary, framework contract, extension seam, observability value, or testable responsibility.
- Reject speculative interfaces, factories, decorators, configuration, compatibility shims, or extension points with no finalized requirement.

### Scout 2: Simplicity and code quality

Review for complexity that does not earn its cost:

- redundant state, caches, fields, flags, or values that can be derived safely;
- parameter sprawl and plumbing caused by a responsibility in the wrong place;
- copy-paste variants that represent one genuine concept and can be unified without creating a generic abstraction;
- leaky abstractions and exposure of internal details;
- stringly typed boundary values where a project enum, value object, constant, or typed DTO already exists;
- unnecessary nullability, defensive branches, wrappers, indirection, conversions, normalization, and impossible-state handling;
- methods, classes, configuration, and extension seams that exist only for hypothetical future use;
- caught exceptions that are swallowed, converted without value, or neither propagated nor intentionally logged;
- comments that narrate what the code does, restate names, describe the patch, mention the task/caller, or document speculation.

Keep concise comments that explain non-obvious why: invariants, lifecycle constraints, concurrency, security, protocol behavior, or unavoidable workarounds. Prefer self-describing names and structure.

Review tests with restraint:

- Tests should protect user-visible behavior, stable contracts, safety boundaries, or known regressions.
- Flag duplicate-layer coverage, implementation-mirroring tests, class-existence tests, trivial getter/enum mechanics, and coverage-only cases only when they provide no contract or regression value.
- Never remove meaningful regression protection merely because the implementation looks simple.
- Prove behavior at the lowest correct layer.
- Do not add sleeps, timing races, retry-until-green loops, broad timeout increases, or production APIs solely for tests.
- Do not add a test for a trivial change merely to increase coverage.

### Scout 3: Runtime and resource efficiency

Review for avoidable work while keeping the solution simple:

- repeated computation, parsing, normalization, serialization, file access, process execution, network/API calls, or database queries;
- Doctrine N+1 queries, unnecessary eager loading, loading full collections when only one item or a filtered subset is needed;
- blocking or heavy work added to startup, per-command, per-message, polling, rendering, Symfony TUI updates, event dispatch/listening, or other hot paths;
- recurring no-op writes, events, state publications, or notifications when nothing changed;
- wrappers that discard or defeat an existing no-change signal;
- race-prone existence pre-checks when direct operation plus precise error handling is safer;
- unbounded collections, retained callbacks/listeners, missing cleanup, leaked processes/resources, or unnecessary copies of large values;
- overly broad scans or reads when a bounded query or targeted operation is available;
- independent expensive operations run sequentially when safe concurrency is already supported and materially useful.

Do not introduce concurrency, caching, batching, or lifecycle machinery for theoretical gains. Recommend it only when the measured or obvious cost is meaningful and ownership, cancellation, cleanup, and error propagation remain clear.

## Phase 3: Aggregate and decide

Wait for all selected audits, retrieving full subagent artifacts when summaries omit evidence.

Then:

1. Deduplicate overlapping findings.
2. Verify every finding against the actual code and repository rules.
3. Accept only findings that are concrete, within scope, preserve finalized behavior, and clearly reduce complexity, duplication, risk, or meaningful runtime cost.
4. Reject false positives, subjective preferences, speculative improvements, architecture violations, and fixes whose complexity exceeds their benefit.
5. Do not expand the task to unrelated pre-existing cleanup.
6. If a finding requires a new product decision, public surface, compatibility policy, or behavior not present in finalized requirements, do not invent it.

Do not create a persistent review report. Keep the accepted fix set in the current session/handoff only.

## Phase 4: Apply accepted fixes

Apply focused accepted fixes directly when they are small and local, unless higher-priority repository workflow instructions require a fork.

Use one fork with a complete handoff when:

- repository instructions require implementation through a fork;
- accepted fixes are substantial, cross several modules, or benefit from isolated experimentation;
- the implementation context would otherwise crowd or destabilize the main session.

Use a single implementation fork initially; re-launch only if it fails or verification exposes a concrete missed issue. The handoff must include the scope packet, accepted findings, exact invariants, allowed files, forbidden expansion, relevant repository instructions, and required Castor validation.

If no findings are accepted, skip implementation and QA.

While fixing:

- preserve behavior and stable contracts;
- keep edits inside the resolved scope, except for the smallest necessary supporting change;
- prefer deletion and direct simplification over moving complexity elsewhere;
- do not add compatibility shims, generic frameworks, new configuration, or public APIs without an explicit requirement;
- update or remove comments only according to the comment rules above;
- preserve useful tests and remove or consolidate tests only when their value is demonstrably redundant;
- do not perform destructive Git operations.

## Phase 5: Verify

Inspect the resulting diff and confirm:

- every edit maps to an accepted finding;
- no intended behavior, public contract, architecture boundary, or required test coverage was lost;
- no unrelated files changed;
- the result is genuinely simpler rather than merely shorter or more abstract.

Follow the testing instructions loaded in Phase 1 before touching or reviewing tests, proposing a test strategy, or running QA/tests.

Run QA only through Castor. Choose the smallest relevant validation set, for example focused tests plus applicable `castor phpstan`, `castor deptrac`, `castor cs-check`, or `castor docs:validate`. Run full `castor check` when repository instructions or the affected runtime path require it. Do not run broad QA merely for ceremony, but do not skip a required gate. If no code was changed, do not run QA unless it is needed to verify a disputed finding.

Do not automatically repeat every lens. Re-run only the relevant selected lens when the fixes are non-trivial or materially alter the design.

## Final response

Briefly report:

- the scope reviewed;
- the accepted simplifications applied, or that no worthwhile cleanup was found;
- any materially important finding rejected as unsafe or out of scope;
- Castor validation commands and results.
