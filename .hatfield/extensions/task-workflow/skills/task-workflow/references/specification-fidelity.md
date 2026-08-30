# Specification fidelity and review

Before assigning implementation or accepting a review:

1. Map every externally visible addition to a finalized requirement. This includes settings, APIs, storage fields, commands, and behavior. Do not add anything without such a requirement.
2. Ask the user about ambiguity that changes behavior or a public API. Plans and handoffs may choose minimal implementation details, but they may not make new product decisions.
3. Follow the latest explicit clarification when it replaces earlier scope.
4. Delete dead, unreachable, superseded, or unsupported code, branches, prompts, adapters, tests, compatibility paths, and procedures in the same change. Do not add fallback or compatibility behavior without an explicit requirement or published contract.
5. Reviewers must compare changed behavior, APIs, and complexity with the requirements. They request changes for additions or complexity that the requirements do not support.

Required error handling and explicitly required local degradation remain valid.

## Reviewer verdict

A CRITICAL, BUG, or SEC finding requires **REQUEST CHANGES**. The same verdict applies to unsupported behavior or APIs, dead code, unsupported fallback behavior, and missing required proof.

Use **APPROVE WITH SUGGESTIONS** for NTH findings, naming preferences, and small optional cleanup unless they affect correctness. Once blockers are fixed, a tiny reduction in lines must not block approval.
