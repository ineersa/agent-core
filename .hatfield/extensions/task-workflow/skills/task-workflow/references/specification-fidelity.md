# Specification fidelity and review

Before assigning implementation or accepting a review:

1. Map every externally visible addition to a finalized requirement. This includes settings, APIs, storage fields, commands, and behavior. Do not add anything without such a requirement.
2. Ask the user about ambiguity that changes behavior or a public API. Plans and handoffs may choose minimal implementation details, but they may not make new product decisions.
3. Follow the latest explicit clarification when it replaces earlier scope.
4. Delete dead, unreachable, superseded, or unsupported code, branches, prompts, adapters, tests, compatibility paths, and procedures in the same change. Do not add fallback or compatibility behavior without an explicit requirement or published contract.
5. Reviewers must compare changed behavior, APIs, and complexity with the requirements. They request changes for additions or complexity that the requirements do not support.

Required error handling and explicitly required local degradation remain valid.

## Independent reviewer assignment

- Send only the identifier-based request allowed by `AGENTS.md`: a PR, a commit, or both. Before a PR exists, send the task branch's committed HEAD. For PR follow-ups, send the PR and new target SHA. Do not append requirements, earlier findings, resolution summaries, test results, preferred outcomes, or instructions to check only fixes.
- Keep checkout, baseline, target revision, scope, and reviewer identity in the task work log, not the assignment. The reviewer obtains requirements, the checkout, and the full change baseline independently from Git, PR metadata, and task records.
- On resumption, require the same full-change review as on the first pass. Previous findings are not the scope, and previous approval is not evidence.
- A framed assignment must receive **REVIEW REFUSED**. Resend only the identifiers to the same reviewer. For **REVIEW BLOCKED**, resolve the missing source evidence before resuming; do not supply an approval narrative.
- Before accepting approval, verify the reported baseline covers the complete task change and the target SHA matches the revision being submitted. A tip-commit check or confirmation of fixes alone does not satisfy the review gate.

## Reviewer verdict

A CRITICAL, BUG, or SEC finding requires **REQUEST CHANGES**. The same verdict applies to mandatory project-rule violations, unsupported behavior or APIs, dead code, unsupported fallback behavior, and missing required proof. Framework-reuse and architecture violations are not automatically optional design suggestions.

Use **APPROVE WITH SUGGESTIONS** for NTH findings, naming preferences, and small optional cleanup unless they affect correctness. Once blockers are fixed, a tiny reduction in lines must not block approval.
