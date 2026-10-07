# Application

- Commit canonical events through `RunCommit` under the run owner lock. Never add an alternate canonical writer.
- Keep state transitions in run-control handlers and external I/O in execution workers.
- Dispatch effects only after persistence and state publication.
- Validate durable run, invocation, and revision identities before committing subagent progress.
- Revalidate cancellation when consuming continuation compaction. Children must not compact.
- Publish immutable child-launch input before dispatch; carry only its reference through Messenger and batch snapshots.
- Keep tool results strict UTF-8 plain data. Reject invalid representations before persistence or model delivery; never repair them silently.
- Preserve replay equivalence and retained-history filtering when changing history, repair, or compaction.
- Do not recreate missing canonical history for ordinary follow-up commands.
