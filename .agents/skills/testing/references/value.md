# Test value

Before adding, retaining, or deleting a case, identify a plausible wrong change that its assertion detects and explain why that change matters.

- Assert observable behavior, protocol, persistence, lifecycle, safety, or a known regression.
- Use independently specified examples; do not copy the production algorithm into expected values.
- Delete setup-only, soft, artificial-state, or implementation-mirroring tests with no meaningful contract.
- Consolidate duplicates only when they protect the same contract and risk. Shared line coverage alone is not evidence.
- Do not delete small tests protecting wire values, security defaults, serialization, or regressions merely because they are small.
- Mocks may prove meaningful arguments or denied calls. They do not prove process termination, database locking, or real signal delivery.
- Exact snapshots are valid when rendered or serialized output is the contract, not incidental prose.
- Do not hide cases in loops, merge unrelated scenarios, or alter discovery to meet a reduction target.
- For each deletion or demotion, record the behavior and exact remaining test method. If no proof remains, explain why the behavior is not required or obtain explicit coverage-loss approval.
- Reviewers must reject unsupported equivalence claims. Expense or flakiness alone does not make a requirement useless.
