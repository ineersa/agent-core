# task-explain: discuss before implementing

Main must read this procedure before doing phase work. The router alone is not enough.

Read [implementation-ownership.md](implementation-ownership.md) and [specification-fidelity.md](specification-fidelity.md). For TUI behavior, also read [tui-proof.md](tui-proof.md).

This phase is read-only. Do not change task status or metadata, edit files, or launch an implementation fork.

1. Read the task, referenced documents, and applicable `AGENTS.md` files.
2. Perform the routing pass from the ownership guide. Main must inspect the likely code paths itself. Use a read-only scout only for a bounded unknown.
3. Apply the specification-fidelity check. Bring unresolved behavior or public API decisions to the user.
4. Present the summary, affected areas, implementation steps, risks, open questions, and suggested validation.
5. Include a tentative ownership decision. State whether main should implement the work or list the proposed fork slices and why each meets the fork criteria.
6. Discuss the plan with the user.
7. Stop. The user runs `task-start` when ready.
