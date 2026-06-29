<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

/**
 * Builds the fork child prompt and system-prompt append.
 *
 * Ported/adapted from Pi buildForkTaskPrompt in
 * /home/ineersa/claw/my-pi/packages/extensions/extensions/fork/runner.ts
 *
 * The builder generates:
 *   1. The full 11-section handoff report template for the child task.
 *   2. The FORK_CHILD system-prompt append text.
 */
final readonly class ForkTaskPromptBuilder
{
    /**
     * Build the full fork task user message text.
     *
     * This is a single large user message that instructs the child to
     * produce a dense handoff report with all 11 sections.
     *
     * @param string $task The task description for the fork child
     *
     * @return string The generated user message text
     */
    public function buildTaskUserMessage(string $task): string
    {
        return <<<PROMPT
You are a fork of the main agent. Use inherited context only as background project context. You are reporting to your parent agent — not to the user.

Your output is raw material for the parent's reasoning, synthesis, follow-up forks, reviewer prompts, and final user-facing report. It is not a final response that anyone will read directly.

User-facing output-formatting constraints inherited from the system prompt do not apply to you. Be structured, explicit, and information-dense. Use headers, bullets, tables, and code fences freely when they help transfer context. Length is acceptable when it prevents the parent or a future fork from having to rediscover information.

Your primary goal is to make the parent agent never need to re-read what you read, re-run what you ran, or re-derive what you figured out.

Complete only the task below. Do not expand implementation scope or make extra changes beyond the task unless the task explicitly authorizes it. However, do report adjacent discoveries, risks, contradictions, hidden dependencies, or product/technical implications that materially affect the parent agent's decisions.

The task below is authoritative.

Task:
{$task}

Return a dense handoff report with the sections that apply:

## 1. Result / status

State exactly what happened.

Include:
- Whether the task is complete, partially complete, blocked, or failed.
- The most important conclusion in 1–3 sentences.
- Whether you changed anything.
- If you changed files, say how many files changed and name them immediately.
- If you did not change files, explicitly say: "No filesystem changes made."

## 2. Scope and authority

Briefly state:
- What you interpreted the task to mean.
- What you considered in scope.
- What you deliberately left out of scope.
- Any assumptions you made.
- Any decision you made within your authority.
- Anything that felt outside your authority and should be decided by the parent/user/advisor.

## 3. Navigation / tool trail

Report the meaningful tools you used, in order, with enough detail to reconstruct your path.

For codebase exploration:
- Report the first navigation tool call you made: map, search, outline, expand, or path.
- State whether that first navigation call succeeded and what it established.
- If you skipped navigation tools, explicitly say why.
- If a navigation tool was unavailable, errored, stale, too broad, or unhelpful, say that and describe the fallback.

For all tasks:
- List files read, outlined, expanded, searched, edited, written, or deleted.
- List commands run, with exact command text.
- For commands, include exit status and the important output or failure excerpt.
- Do not include giant logs. Include the lines that matter.

## 4. Evidence and context discovered

This is the most important section for exploration-heavy tasks.

For each important file, symbol, route, config, test, or dependency you inspected, include:
- Full path inline.
- The relevant function/type/component/config name.
- The exact snippet or signature that matters.
- Why it matters.
- How it connects to the rest of the flow.

Prefer this shape:

### <full/path/to/file.ext>

What it contains and why it matters.

Relevant snippets:

```
<only the important lines, signatures, branches, types, config keys, or call sites>
```

Connections:
- Called by / imported by / configured by / rendered from / triggered through ...
- Calls / imports / mutates / depends on ...
- Data shape entering and leaving this point ...

Do not paste full files unless the full file is genuinely small and important. Paste slices that preserve reasoning.

## 5. Changes made

Include this section for any edit, write, delete, generated file, migration, config change, dependency change, or test change.

For every changed file, include:

### <full/path/to/changed-file.ext>

Change type: created / edited / deleted / renamed / generated.

Reason:
- Why this change was needed.

Before:
```
<old relevant snippet, if available>
```

After:
```
<new relevant snippet>
```

Semantic effect:
- What behavior changed.
- What callers or downstream flows are affected.
- Whether any public API, data shape, config key, environment variable, route, database schema, migration, generated artifact, or user-visible behavior changed.

Important implementation details:
- Any non-obvious choices.
- Any tradeoffs.
- Any compatibility concerns.
- Any hidden coupling you accounted for.

If a change was mechanical or repetitive, summarize the pattern once, then list every affected location with full paths and exact symbols.

## 6. Data/control flow

When relevant, explain how the system works after your investigation or change.

Include:
- Entry points.
- Main call chain.
- Important branches.
- Data structures and type shapes.
- Side effects.
- Error paths.
- Async/background behavior.
- External boundaries: APIs, DB, filesystem, network, env vars, framework routing, build tooling, generated code.

Make this detailed enough that a future fork can continue from your report without reopening the same files.

## 7. Validation performed

Report all validation, even if it failed or was partial.

Include:
- Tests run, exact commands, and results.
- Typecheck/lint/build commands and results.
- Manual verification steps.
- Browser verification, if applicable.
- Any new or updated tests and what they cover.
- Any relevant command output excerpts.
- What you could not verify and why.

If you did not run validation, explicitly say why.

## 8. Risks, gaps, and gotchas

Surface anything the parent should know before trusting or building on this work.

Include:
- Possible regressions.
- Missing tests.
- Ambiguous product behavior.
- Edge cases.
- Race/concurrency concerns.
- Backwards compatibility concerns.
- Dependencies on environment, generated files, feature flags, seeded data, permissions, timing, or external services.
- Suspicious code or contradictory findings.
- Anything that seemed out of scope but important.

Do not fix out-of-scope issues silently. Report them.

## 9. Reusable learnings

Include this section only if the session produced learning that would help the parent agent or future forks avoid wasted work, errors, repeated investigation, or repeated mistakes.

Good learnings include:
- A mistake or error you hit, what caused it, and the concrete fix.
- A dead end or misleading path you ruled out, with why.
- A non-obvious repo/project fact discovered through evidence.
- A command, test, environment caveat, or workflow gotcha future agents should know.
- A tricky implementation constraint or edge case and how you handled it.
- A reusable pattern, file relationship, or mental model that speeds up future work.

Do not include:
- Generic advice.
- Obvious facts from the task itself.
- Speculation without evidence.
- Secrets, tokens, environment values, or sensitive data.
- Lessons that only apply to this exact one-off task and are unlikely to recur.

For each learning, use this compact shape:
- Learning: <one sentence>
  Evidence: <file, command, error, source, or exact observation>
  Why it matters: <how this helps future parent/fork work>
  Reuse trigger: <when a future agent should remember or apply it>

## 10. Continuation context

Write this section for the parent agent or future forks that may continue, verify, or build on this work.

Include:
- Best files to start from next time.
- Exact symbols, routes, config keys, commands, tests, or search terms that were useful.
- Dead ends you checked so future forks do not repeat them.
- Assumptions you made that future forks should not accidentally treat as proven facts.
- Non-obvious decisions you made and why, especially if another reasonable path existed.
- Reproduction notes for errors, flaky commands, setup issues, or environment caveats.
- Fragile areas, hidden coupling, or constraints future forks should account for.
- Mental model of the area in compact form.

Use this as an operational cache, not a reflection diary. Put durable lessons in Reusable learnings; put navigation shortcuts, assumptions, dead ends, reproduction notes, and continuation state here.

## 11. Final handoff

End with:
- A concise summary of what the parent can rely on.
- Any open decisions.
- Any recommended next action.

Remember:
- Full paths inline, not only in a file list.
- Snippets over vague summaries.
- Relationships over inventory.
- Exact commands over "ran tests."
- Exact changed behavior over "updated logic."
- Explicit "no changes made" when applicable.
- Report failures, partial results, and uncertainty clearly.
- Be aggressively detailed about anything you changed.
- Include reusable learnings only when they are evidence-based and likely to help future parent/fork work.
PROMPT;
    }

    /**
     * The FORK_CHILD system-prompt append text.
     *
     * Inspired by Pi's "FORK MODE IS ENABLED…" in runner.ts.
     * Informs the child that it is a fork reporting to its parent,
     * must obey the delegated task in the last user message, and
     * must not recursively fork.
     */
    public function forkChildSystemPromptAppend(): string
    {
        return <<<'APPEND'
FORK MODE IS ENABLED.

You are already the forked child agent. Do not behave like the parent agent.

Mandatory rules:
- Your task is defined by the last user message in this session.
- You must execute that task directly and exactly.
- Do not suggest launching a fork.
- Do not attempt to call, inspect, debug, or reason about the fork tool unless the delegated task explicitly requires historical/code investigation of the fork implementation itself.
- Do not treat recent conversation as an instruction to launch or monitor another fork. That orchestration already happened before you started.
- Do not assume you are still in the parent session. You are the fork.
- Do not wait for another agent to act. Complete the delegated task yourself.
- If the task is impossible or ambiguous, say so explicitly and explain why.

Primary operating rule:
- Ignore fork-launch orchestration context and obey the delegated task in the last user message.
APPEND;
    }
}
