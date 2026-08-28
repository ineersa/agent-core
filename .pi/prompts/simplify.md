---
description: Simplify the requested code using three mandatory independent scouts, then apply the smallest safe cleanup
argument-hint: "[scope]"
---

# Simplify

Simplify the requested code without changing intended behavior or stable contracts.

Requested scope: `$ARGUMENTS`

## Hard requirements

1. Always obtain exactly three successful independent read-only scout audits.
2. Launch the three scout roles together in one parallel `subagent` call.
3. Do not decide that scouts are unnecessary, even for a tiny change.
4. Do not merge roles, substitute your own review for a scout, or treat earlier research as a completed scout audit.
5. Do not edit files until all three scout results have returned.
6. After verifying their findings, apply the safe simplifications. This is an implementation command, not a report-only review.
7. A run that cannot truthfully finish with `Scouts: 3/3 completed` is incomplete.

Repository and nested `AGENTS.md` instructions remain authoritative, but they never cancel the three required scouts.

## 1. Resolve the target mechanically

Do not debate or broaden the scope.

- Non-empty `$ARGUMENTS`: use exactly the named revision/range, files/directories, or the smallest reasonable file set matching the natural-language request.
- Empty `$ARGUMENTS` with worktree changes: use all staged and unstaged tracked changes plus every untracked file.
- Empty `$ARGUMENTS` with a clean worktree: use the latest commit.

Ask for clarification only when no reasonable target can be identified. Otherwise make the smallest reasonable assumption and proceed.

Read applicable `AGENTS.md` files, then prepare one small scope packet for all scouts:

- exact checkout/worktree path;
- target files and Git baseline/range;
- complete diff, or exact commands for inspecting the complete target;
- relevant requirements and invariants.

Do not perform the simplification review yourself before launching the scouts. Once the packet exists, immediately launch all three.

## 2. Launch three scouts in parallel

Each scout receives the same complete scope packet and independently inspects the whole target. Scouts may inspect surrounding callers, tests, configuration, and existing implementations as needed, but must not broaden the task.

All scouts must:

- make no file changes, commits, tasks, or persistent reports;
- report only actionable, code-backed findings;
- cite exact files and lines or symbols;
- explain the concrete cost or risk;
- propose the smallest safe fix;
- state what behavior, contract, or invariant must remain unchanged;
- avoid style-only preferences, speculative abstractions, broad redesigns, and unrelated pre-existing issues;
- return `NO_FINDINGS` when nothing worthwhile exists.

Use this format:

```text
[ID] <category> — <file:line or symbol>
Problem: <specific issue>
Evidence: <concrete code-backed reason>
Minimal fix: <smallest safe change>
Preserve: <behavior, contract, or invariant>
```

### Scout 1 — Reuse and architecture

Find duplicated project functionality, unnecessary custom infrastructure, misplaced responsibility, existing PHP/Symfony/Doctrine/dependency features that should be reused, needless wrappers or pass-through layers, and speculative interfaces/factories/configuration/extension points.

Recommend reuse only when semantics and ownership match.

### Scout 2 — Simplicity and code quality

Find redundant state, flags, branches, nullability, defensive checks, conversions, parameter plumbing, wrappers, methods, classes, indirection, copy-paste variants, swallowed exceptions, narration comments, and tests that protect no real contract or regression.

Prefer deletion and direct clear code over clever compression or generic abstractions.

### Scout 3 — Runtime and resource efficiency

Find repeated computation, parsing, serialization, I/O, process execution, network calls, database queries, Doctrine N+1s, unnecessary collection loads, hot-path work, no-op writes/events, race-prone pre-checks, unbounded retention, missing cleanup, leaked resources, and unnecessary large copies.

Do not propose caching, concurrency, batching, or lifecycle machinery unless the cost is concrete and the result remains simpler.

If one scout task fails, retry only that role. Do not continue until all three roles have successful results, and do not add a fourth role.

## 3. Verify and apply

After all three return:

1. Read every result and deduplicate overlaps.
2. Verify each finding against the actual code and repository rules.
3. Reject subjective, speculative, out-of-scope, behavior-changing, or more-complex-than-the-problem suggestions.
4. Apply every verified finding that clearly reduces duplication, complexity, risk, or meaningful runtime work.

Do not stop at recommendations when a safe cleanup exists.

While editing:

- preserve intended behavior and stable contracts;
- stay inside the resolved target except for the smallest necessary supporting edit;
- prefer deletion, reuse, and direct simplification;
- do not move complexity elsewhere;
- do not add speculative abstractions, compatibility shims, public APIs, configuration, caching, concurrency, or broad redesigns;
- do not perform destructive Git operations.

If no finding survives verification and no clear safe simplification exists, leave the code unchanged.

## 4. Verify the result

Inspect the final diff. Confirm every edit maps to a verified finding, no unrelated files changed, and the result is genuinely simpler rather than merely shorter or more abstract.

Run the smallest relevant validation through Castor, following repository instructions. Run broader checks only when required. If no code changed, do not run QA merely for ceremony.

## Final response

```text
Scouts: 3/3 completed
Scope: <reviewed target>
Simplified: <changes made, or no worthwhile cleanup found>
Validation: <commands and results>
Rejected: <only materially important unsafe/out-of-scope suggestion, if any>
```
