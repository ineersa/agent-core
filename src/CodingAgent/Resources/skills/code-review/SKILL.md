---
name: code-review
description: Code review a pull request or branch.
---

<!-- Description source: anthropics/claude-plugins-official@b5b01ac4f889fec0c4c6bb89f27ca7c8574473f6,
plugins/code-review/commands/code-review.md. Adaptation: add branch scope. -->

# Code review

Main orchestrates this workflow with existing subagents. This is an instruction-based
procedure, not a native review tool or an approval gate. Review never authorizes
fixes, tests, publication, task transitions or merging. Honor explicit manual/skipped
review choices; a direct review request authorizes only this additional review.
Implementation children do not orchestrate reviews.

## Resolve the scope

- Resolve the user's free-form request from the session and read-only Git/PR/task
  metadata. A bare request means the current change when unambiguous. Ask only
  when meaningful scope ambiguity remains. Do not infer that the primary checkout
  is the task worktree or silently review only the last commit.
- First version: full committed reviews in a clean, matching checkout. For PRs,
  establish actual repository, base/head and matching checkout using read-only
  metadata. For branches, explicitly establish the comparison base. Pin base-ref,
  merge-base and head SHAs separately and inventory the entire merge-base-to-head
  change. A standalone commit uses its sole parent (empty tree for a root);
  a merge commit needs an explicit branch comparison. Reject ambiguous/missing
  refs, dirty checkout or mismatched PR head; never stash/reset/create a worktree.
- Use Git inspection without external diff/textconv execution. Do not treat stale
  remote-tracking refs as current server metadata. If preparation/fetch is needed,
  use existing authorized workflows separately.
- Requests for uncommitted review or changes-since-review are not supported here.
  Explain the limitation and obtain agreement on a full committed review rather
  than quietly changing scope. After fixes, repeat a full review.
- Resolve source-attributed finalized requirements and mandatory project rules.
  User focus adds emphasis, not a silent exclusion of other changed contracts.

## Fresh reviewer and auditor

Verify `reviewer`, `auditor` and `verifier` exist in the runtime catalog. If missing, explain
`hatfield agents:init` installation; do not substitute self-review. Existing custom
or old agents may differ: inspect the discovered definitions when available and
report incompatible instructions/tool policy. Never overwrite customized agents.

Launch a NEW `reviewer` subagent and a NEW `auditor` subagent, each with the exact
checkout, repository/PR identity, pinned comparison SHAs, changed-file inventory,
neutral requirements and explicit focus; submit the two calls together so they run
concurrently. Let each read the full diff and surrounding code, not just a
prompt-sized excerpt. Send neither implementation history, test-success claims,
desired verdict, prior approval, candidate bugs invented by main, or main's
conversation. Use subagents, not conversation-inheriting forks.

The reviewer returns a scope, coverage/limitations, a trace table, and defect
candidates with stable IDs (`R1`, `R2`, ...), area, priority, file/line, trigger,
impact/evidence (including what is uncertain) and minimal correction. The auditor
returns a scope, coverage, and candidates with stable IDs (`A1`, `A2`, ...) of kind
`rule_violation`, `reuse`, `docs` or `test_gap`, each with the quoted rule line or
the named existing facility. A `Notices` list in either report is not candidates:
carry its items into the final report as unverified concerns. Retrieve omitted
artifact output through existing authorized facilities. A missing, failed, malformed
or materially partial report from either is NOT an empty review.

Either report may end with a **Lookups for main** list: names of packages, classes,
functions or versions the pass could not verify from local sources. Decide which
lookups the candidates need, perform them with your own web tools, and give the
verifier the results with their sources as untrusted evidence. No review agent has
web tools; do not relay raw pages to one.

## Fresh verifier

Before proceeding, recheck checkout cleanliness, pinned revisions and actual PR
head/base identity. A changed target makes the result stale; retain historical
observations without approving the current change. Do not write concurrently to
the reviewed checkout while a review stage runs.

Skip verification ONLY when both reports are complete with no candidates. Otherwise
use the adapted verification phase below:

<!-- Source: Piebald-AI/claude-code-system-prompts@d545d4147af1ff0a165622ecd55e7fdc0d51a1f6
(tag v2.1.325), system-prompts/skill-code-review-phase-2-verify-3-state.md.
Third-party extraction, not Anthropic's published source; upstream block ccVersion 2.1.173.
Adaptations: one NEW verifier for the entire deduplicated set, not per-candidate
fan-out; deduplication spans the reviewer's and the auditor's lists; PLAUSIBLE maps
to uncertain and is not actionable. License: references/LICENSE.txt. -->

Dedup candidates across both lists that point at the same line/mechanism, keeping
the one with the most concrete failure scenario. For the remaining candidates, run **one
verifier** via the `subagent` tool: give it the diff, the relevant
file(s), and the candidates, and have it return exactly one of CONFIRMED,
PLAUSIBLE, or REFUTED for each candidate, using the verifier's three-state definitions.

Keep only candidates where the vote is CONFIRMED as actionable findings.
Report PLAUSIBLE separately as unresolved checks (`uncertain`), never clean approval.

<!-- End adapted verification phase. -->

Merge by underlying defect/remedy, not just equal IDs or line numbers. Preserve
the strongest failure scenario and union applicable rule evidence when merging;
retain original IDs as aliases to the surviving ID for traceability. Reviewer's
deduplication does not replace main's check. Do not send duplicates to the verifier.

Pass the same pinned scope/requirements, deduplicated candidate claims and any
lookup results with their sources. Pass the report text or an accessible
artifact/file reference; opaque child references are not automatically readable by
another child. Main retrieves and relays evidence when necessary. Do not forward
the reviewer's or the auditor's reasoning transcript, or direct the verifier toward
any disposition.

Require one `confirmed`, `refuted`, or `uncertain` decision with evidence per candidate.
Map the upstream labels to these handoff states (PLAUSIBLE → uncertain).
Check every surviving ID is covered exactly once. Missing or
contradictory output is incomplete; do not assume confirmation or rejection. The
verifier may correct severity/remedy. A genuinely new issue is an unverified concern,
not a verified finding, and does not trigger recursive agents or automatic repairs.
If verification exposes duplicate aliases, show one final finding per underlying
defect, preserving combined evidence and all dispositions in the stage reports.

## Report honestly

Recheck target freshness at final reporting, including read-only PR identity when
applicable. Failed freshness checks prevent a current successful-review verdict.

- **Changes requested:** confirmed introduced defects, or confirmed rule violations and reuse findings.
- **No actionable findings:** complete coverage with no candidates, or all actionable
  candidates refuted after completed verification. This is not proof of correctness.
- **Inconclusive:** uncertain actionable claims or material coverage gaps; retain any
  confirmed findings, but do not issue a clean approval.
- **Incomplete / failed / cancelled / stale:** preserve that outcome and available
  partial evidence. Never publish unverified candidates as confirmed findings.

Report scope and SHAs, confirmed findings (exact trigger/impact/minimal correction),
unresolved checks, notices carried from either pass, coverage and validation
actually inspected. Omit empty sections and generic praise. Keep refutation
evidence in the available stage reports; summarize it when useful. Tests not run
remain unrun.

Use normal background subagent calls when requested/useful, but these are TWO
stages coordinated by main — reviewer and auditor concurrently, then the verifier
— not one atomic background operation. Completion of the reviewer or the auditor
is progress, not completion of review. Cancellation stops the active stage through
existing controls; after cancellation do not launch a verifier. Continue on
automatic completion notices without polling. Do not resume a prior agent to
represent a fresh independent review; resume only to clarify its own existing
report, then accurately describe that clarification.
