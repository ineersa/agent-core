# Specification Fidelity and Review Gate

Before assigning implementation or accepting review:

1. Map every externally visible addition—setting, API, storage field, command, or behavior—to an exact finalized requirement. Unmapped additions are forbidden.
2. Resolve ambiguity affecting behavior or public surface with the user. Plans and handoffs may choose minimal mechanics, not new product decisions.
3. The latest explicit clarification overrides superseded scope.
4. Delete dead, unreachable, superseded, or unsupported code, branches, prompts, adapters, tests, compatibility paths, and procedures in the same change. Do not add fallback or compatibility behavior without an explicit requirement or published contract.
5. Reviewers inventory changed surface and complexity against the requirements and request changes for unmapped additions or unnecessary complexity.

Required error handling and explicitly required local degradation remain valid.

## Reviewer verdict

CRITICAL, BUG, SEC, unmapped surface, dead code, uncited fallback behavior, or missing required proof means **REQUEST CHANGES**. NTH, naming, and pure ponytail micro-shrinks mean **APPROVE WITH SUGGESTIONS** unless correctness is affected. A remaining tiny line shrink must not block approval after blockers are fixed.
