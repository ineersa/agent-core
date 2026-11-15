---
builtin: true
description: Review committed changes using main-orchestrated reviewer and verifier agents.
---

# Code review

`/code-review` loads the bundled review skill into a normal main-agent turn. Main
resolves the change, launches a fresh `reviewer`, then launches a fresh `verifier`
when there are candidates. This is a skill-driven procedure, not a native review
tool or a hardened approval gate.

## Setup

The skill ships with Hatfield and is materialized automatically through normal
[skill discovery](skills.md). Install the bundled agent definitions separately:

```bash
hatfield agents:init
```

Existing definitions are not overwritten by default. To update installed starters,
inspect/back up your customizations first, then deliberately use
`hatfield agents:init --force`. This overwrites **all bundled agent filenames**, not
just reviewer/verifier; unrelated agent filenames remain untouched. Project agent
definitions can override the installed starters. Restart Hatfield after updating
so its catalog includes both roles and the refreshed instructions.

Both review starters inherit the parent's model and reasoning by default; ordinary
[agent frontmatter](agents.md) can override either role. They allow `read` and shell
inspection, deny inherited MCP tools, and instruct the models not to modify files,
run tests, install packages or publish results. Shell access is not an OS-enforced
read-only sandbox; normal permissions and approval controls still apply.

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
repeat the full review. No checkout preparation, stash or reset is implicit.

The command is available when the `code-review` skill is discovered; `--no-skills`
hides it unless that skill is explicitly included through `--skills-path`. As with
other built-in slash commands, it takes precedence over a same-named prompt template.
You can also load `/skill:code-review` and supply your request in conversation.
There is no new prompt-template distribution mechanism.

## What the stages do

The reviewer receives pinned scope and neutral requirements, not main's implementation
conversation or desired verdict. It searches for actionable introduced defects and
mandatory project-rule violations, tracing surrounding contracts and contradictory
evidence. Each candidate has an ID, location, priority, trigger, impact and minimal
correction. Optional improvements are separate; there is no finding quota.

Reviewer and main deduplicate by underlying defect/remedy before verification,
keeping the strongest failure scenario and combining applicable rule evidence.
The verifier receives the same scope and deduplicated claims in a fresh context.
It independently inspects the evidence and returns `confirmed`, `refuted` or
`uncertain` for every ID. It can correct severity/remedy but does not perform another
broad review or silently promote new observations to verified findings. Clear static
evidence is valid without executing tests.

Main relays reports/artifacts, checks completeness and target freshness, and reports
confirmed findings plus unresolved checks and actual limitations. Verification is
skipped only after a complete empty reviewer report. Missing/failed reports are
incomplete, not clean; uncertain actionable claims prevent a clean conclusion.
Same-model verification is allowed but does not guarantee independent errors.

The prompt core preserves the Codex review rubric and the Claude finder-angle and
three-state verification blocks from Piebald's **third-party extraction** (not
Anthropic's published source), tag `v2.1.325`. Each copied block records its source
path, pinned commit and adaptations. The skill description is adapted from
Anthropic's official review command. Intentional differences: one reviewer executes
all passes, one verifier checks the set, only introduced defects are in scope, and
upstream `PLAUSIBLE` maps to unresolved `uncertain`, not an actionable finding.
License texts/notices ship in the bundled skill's `references/LICENSE.txt`.

## Background work and limits

The two stages are separate native subagent calls, coordinated through main's
context. They can use normal background execution and completion notices, but there
is no single review operation/handle or atomic lifecycle. Reviewer completion alone
is not review completion. After cancellation main must not start the next stage.
Do not modify the reviewed checkout while review is running; a changed target makes
the report stale.

Sequencing, report checks and freshness are instruction-enforced, not host-validated.
A report must identify its actual revision, inspected validation and coverage gaps.
It never authorizes code fixes, test execution, PR comments, task transitions or
merge, and does not replace an explicit manual/skipped-review choice.
