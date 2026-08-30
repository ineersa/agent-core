---
description: Simplify scoped code using three fixed independent scout audits, then apply the worthwhile cleanup
argument-hint: "[scope]"
---

# Simplify

Simplify the requested code without changing intended behavior.

Requested scope: `$ARGUMENTS`

## Mandatory workflow

1. Resolve the target mechanically.
2. Launch exactly three read-only scouts together in one parallel `subagent` call.
3. Use the three scout prompts below exactly as written.
4. Wait for all three scouts.
5. Verify their findings against the code.
6. Apply the worthwhile simplifications directly.
7. Run the smallest relevant validation required by repository instructions.

Do not replace a scout with your own review. Do not begin reviewing or editing code before the three scouts have been launched. Do not launch an implementation subagent.

## Resolve the target

Read applicable repository instructions, but do not summarize or pass them to the scouts. The scouts can read repository instructions from their checkout.

Resolve `$ARGUMENTS` as follows:

- A commit, revision range, or PR: use that exact change.
- Files or directories: use exactly those paths.
- A natural-language scope: resolve it to the smallest matching file set.
- Empty arguments with a dirty worktree: use all staged, unstaged, and untracked changes.
- Empty arguments with a clean worktree: use the latest commit.

Use Git metadata and filenames to resolve the target. Do not analyze the implementation before launching the scouts.

Set each scout's `cwd` to the exact checkout through the subagent tool. Build one identical target block for all three scouts using only the applicable form:

```text
Target: working tree changes
Files:
- <path>
- <path>
```

```text
Target: commit <sha>
Files:
- <path>
- <path>
```

```text
Target: range <base>..<head>
Files:
- <path>
- <path>
```

```text
Target: PR <number or ref>
Files:
- <path>
- <path>
```

```text
Target: current code in these paths
Files:
- <path>
- <path>
```

The target block may contain only the target selector and file paths. Do not include the user request, task description, requirements, acceptance criteria, intended implementation, diff summary, suspected problems, priorities, invariants, architecture commentary, parent conclusions, or previous agent findings.

## Exact scout launch contract

Launch the three scouts in one parallel call.

For each scout, copy its corresponding prompt below verbatim and replace only `{{TARGET}}` with the identical target block. Add no prefix, suffix, title, greeting, explanation, handoff, context, or extra instruction. Do not paraphrase, summarize, expand, or “improve” the prompts.

Names or tool metadata may identify the scouts as `reuse`, `simplicity`, and `efficiency`, but that metadata must not be added to their task prompts.

### Scout 1 prompt — reuse

```text
You are a read-only code scout. Inspect the target only for concrete reuse opportunities.

Do not edit files. Treat existing behavior as fixed. Do not assess whether the code fulfills its original task, whether requirements are correct or complete, or how the feature should have been implemented. Do not infer or discuss the parent task. Ignore commit messages, branch names, PR titles/descriptions, issue text, and other task metadata. Do not review general correctness or report missing behavior. Do not propose new features, broad redesigns, or speculative abstractions.

Search the repository and the APIs already available through PHP, Symfony, Doctrine, and installed dependencies for existing code that makes scoped code duplicate or unnecessary. Look for existing services, helpers, types, mappers, repositories, framework facilities, and adjacent implementations. Recommend reuse only when semantics and ownership match. Prefer deletion or direct reuse over introducing another abstraction.

Return only findings in this form:

<file:line or symbol> — <specific reuse or deletion opportunity>
Evidence: <the existing code or API, including its location>
Change: <the smallest simplification>

If there are no concrete findings, return exactly: NO_FINDINGS

{{TARGET}}
```

### Scout 2 prompt — simplicity

```text
You are a read-only code scout. Inspect the target only for avoidable code complexity.

Do not edit files. Treat existing behavior as fixed. Do not assess whether the code fulfills its original task, whether requirements are correct or complete, or how the feature should have been implemented. Do not infer or discuss the parent task. Ignore commit messages, branch names, PR titles/descriptions, issue text, and other task metadata. Do not review general correctness or report missing behavior. Do not propose new features, broad redesigns, or speculative abstractions.

Find code that can be made smaller and clearer without changing behavior: redundant state, fields, flags, branches, nullability, defensive checks, conversions, normalization, parameter plumbing, wrappers, pass-through methods, classes, indirection, copy-paste variants, swallowed exceptions, narration comments, and tests that duplicate the same contract without adding regression value. Do not replace straightforward code with clever compression or a generic abstraction.

Return only findings in this form:

<file:line or symbol> — <specific unnecessary complexity>
Evidence: <why the code is redundant or needlessly indirect>
Change: <the smallest simplification>

If there are no concrete findings, return exactly: NO_FINDINGS

{{TARGET}}
```

### Scout 3 prompt — efficiency

```text
You are a read-only code scout. Inspect the target only for unnecessary runtime or resource work that can be removed while simplifying the code.

Do not edit files. Treat existing behavior as fixed. Do not assess whether the code fulfills its original task, whether requirements are correct or complete, or how the feature should have been implemented. Do not infer or discuss the parent task. Ignore commit messages, branch names, PR titles/descriptions, issue text, and other task metadata. Do not review general correctness or report missing behavior. Do not propose new features, broad redesigns, caching systems, concurrency, batching frameworks, or speculative performance machinery.

Find concrete repeated or avoidable work: computation, parsing, normalization, serialization, file access, process execution, network calls, database queries, Doctrine N+1 queries, loading broader data than needed, hot-path work, no-op writes or events, redundant existence checks, retained resources, missing cleanup, and unnecessary copies of large values. Report only cases where the smallest fix both reduces work and keeps the implementation simple.

Return only findings in this form:

<file:line or symbol> — <specific avoidable work>
Evidence: <where and why the work is unnecessary>
Change: <the smallest simplification>

If there are no concrete findings, return exactly: NO_FINDINGS

{{TARGET}}
```

If a scout call fails, retry only that scout with the same exact prompt and target block. Do not alter the prompt and do not add a fourth scout.

## Verify and simplify

After all three scouts return:

1. Inspect the scoped code and verify every finding.
2. Deduplicate overlaps.
3. Reject findings that are unsupported, subjective, speculative, outside the target, behavior-changing, or more complex than the code they replace.
4. Apply every remaining worthwhile simplification directly.

The scouts discover candidates; they do not decide task correctness or implementation direction. Do not expand the work into fixing missing requirements, redesigning the feature, or completing the original task.

Keep edits within the target except for the smallest necessary direct reuse change. Prefer deletion, existing code, and straightforward control flow. Do not create compatibility layers, extension points, configuration, public APIs, or new abstractions without an immediate need demonstrated by the scoped code.

Inspect the final diff and run the smallest relevant validation required by repository instructions. If no change survives verification, leave the code unchanged and do not run checks merely for ceremony.

## Final response

```text
Scouts: 3/3 completed
Scope: <target>
Simplified: <changes made, or no worthwhile simplification found>
Validation: <commands and results, or not run because no code changed>
```
