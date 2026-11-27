---
builtin: true
description: Review committed changes using main-orchestrated reviewer, auditor and verifier agents.
---

# Code review

`/code-review` loads the bundled review skill into a normal main-agent turn. Main
resolves the change, launches a fresh `reviewer` and a fresh `auditor` concurrently,
then launches a fresh `verifier` when there are candidates. This is a skill-driven
procedure, not a native review tool or a hardened approval gate.

Run at most one review cycle per task/change by default: reviewer and auditor,
then at most one verifier pass over their combined findings. Main must explicitly
ask the user and obtain approval before any additional cycle or review retry. If
asking is unavailable, stop after one cycle. Fixes, new commits and incomplete
reports do not reset the limit; validate fixes and report remaining gaps instead
of automatically launching another review. Children return approval requests to
main rather than repeating review themselves.

## Setup

The skill ships with Hatfield and is materialized automatically through normal
[skill discovery](skills.md). Install the bundled agent definitions separately:

```bash
hatfield agents:init
```

Definitions install into `~/.hatfield/agents/`. If any bundled filename already
exists there, the command lists the collisions and installs nothing. To update installed starters,
inspect/back up your customizations first, then deliberately use
`hatfield agents:init --force`. This overwrites **all bundled agent filenames**, not
just the three review agents, including a model line you customized; unrelated
agent filenames remain untouched. Project agent definitions can override the
installed starters. Restart Hatfield after updating so its catalog includes all
three roles and the refreshed instructions.

The `reviewer` uses `openai-codex/gpt-6.2-astra`; the `auditor` uses
`opencode-go/deepseek-v4.1-flash` and the `verifier` `opencode-go/deepseek-v4.1-flash`,
all with `high` thinking. Ordinary [agent frontmatter](agents.md) can override any
role. All three allow `read` and shell inspection and instruct the models not to
modify files, run tests, install packages or publish results. The `reviewer` and
`auditor` add the three `websearch` tools, so they can check vendor and platform
behavior against the documentation for the installed version; they put only names
into queries and treat fetched pages as data. The `verifier` denies inherited MCP
tools. Shell access is not an OS-enforced read-only sandbox; normal permissions and
approval controls still apply.

## Use

```text
/code-review
/code-review validate PR 657
/code-review validate the work done on our current task
/code-review review this branch against origin/main, focus on cancellation
```

Main resolves and pins the actual checkout and full committed comparison, not only
the tip commit. A bare request uses the current change when unambiguous; otherwise
main asks. The first version requires a clean matching checkout and supports full
PR/branch reviews or an explicit standalone non-merge commit. Dirty/uncommitted
reviews and incremental changes-since-review are outside this workflow; after fixes,
do not repeat review without explicit user approval. If another cycle is approved,
review the full comparison. No checkout preparation, stash or reset is implicit.

The command is available when the `code-review` skill is discovered; `--no-skills`
hides it unless that skill is explicitly included through `--skills-path`. As with
other built-in slash commands, it takes precedence over a same-named prompt template.
You can also load `/skill:code-review` and supply your request in conversation.
There is no new prompt-template distribution mechanism.

## What the stages do

The reviewer and the auditor receive pinned scope and neutral requirements, not
main's implementation conversation or desired verdict, and do not see each other's
work.

The reviewer hunts introduced defects: correctness, lifecycle and concurrency,
wiring, architecture, security and performance. It reads every hunk and its
enclosing code, traces changed functions to their callers, and runs scenario passes
for lifecycle and wiring, because a review most often misses how a change interacts
with unchanged code rather than the changed lines themselves. It reports a
candidate whenever it can state the mechanism concretely, even when the trigger is
uncertain, and says what is uncertain; the verifier filters false positives.

The auditor checks the repository's own rules and reuse. It quotes the rule line a
change disobeys, names the existing facility a hand-rolled mechanism replaces
(searching the project, its declared dependencies, then the web), and flags stale
documentation and statically missing test coverage. A preference without a quoted
rule or a named, usable facility is not a candidate.

Each candidate has an ID (`R…` or `A…`), location, priority, trigger, impact and
minimal correction. There is no finding quota.

Reviewer, auditor and main deduplicate by underlying defect/remedy before
verification, keeping the strongest failure scenario and combining applicable rule
evidence. The verifier receives the same scope and deduplicated claims in a fresh
context. It independently inspects the evidence and returns `confirmed`, `refuted`
or `uncertain` for every ID. It refutes only when the claimed mechanism does not
occur: a real mechanism with a smaller impact is confirmed with a corrected
priority, and a real mechanism whose trigger cannot be established is uncertain.
It does not perform another broad review or silently promote new observations to
verified findings. Clear static evidence is valid without executing tests.

Main relays reports/artifacts, checks completeness and target freshness, and reports
confirmed findings plus unresolved checks, unverified notices from either pass and
actual limitations. Verification is skipped only after complete reviewer and auditor
reports with no candidates. A missing or failed report is incomplete, not clean;
uncertain actionable claims prevent a clean conclusion. Same-model verification is
allowed but does not guarantee independent errors.

The prompt core preserves the Codex review rubric and the Claude finder-angle and
three-state verification blocks from Piebald's **third-party extraction** (not
Anthropic's published source), tag `v2.1.325`. Each copied block records its source
path, pinned commit and adaptations. The skill description is adapted from
Anthropic's official review command. Intentional differences: one reviewer executes
all passes, one verifier checks the set, only introduced defects are in scope, rule
citations and reuse checks belong to the auditor, and upstream `PLAUSIBLE` maps to
unresolved `uncertain`, not an actionable finding.
License texts/notices ship in the bundled skill's `references/LICENSE.txt`.

## Background work and limits

The stages are separate native subagent calls, coordinated through main's context.
They can use normal background execution and completion notices, but there is no
single review operation/handle or atomic lifecycle. Completion of the reviewer or
the auditor alone is not review completion. After cancellation main must not start
the next stage.
Do not modify the reviewed checkout while review is running; a changed target makes
the report stale.

Sequencing, report checks and freshness are instruction-enforced, not host-validated.
A report must identify its actual revision, inspected validation and coverage gaps.
It never authorizes code fixes, test execution, PR comments, task transitions or
merge, and does not replace an explicit manual/skipped-review choice.
