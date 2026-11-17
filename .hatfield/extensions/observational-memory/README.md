# Observational memory

Thin client for Librarian-owned observational memory. Librarian alone observes,
reflects, drops and stores memory, reading canonical Hatfield `events.jsonl`
journals directly. This extension never opens, migrates or deletes `om.sqlite`.

## Configuration

Enable the installed package and configure its required full HTTP MCP endpoint:

```yaml
extensions:
  enabled:
    - Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension
  settings:
    observational_memory:
      librarian:
        url: http://127.0.0.1:8093/_mcp
        timeout_seconds: 30
        # token: env:LIBRARIAN_TOKEN
```

The URL must be absolute HTTP(S), without credentials, query or fragment.
`timeout_seconds` defaults to 30 and is bounded to 1–300. The optional bearer
`token` accepts a literal or `env:NAME`; omit it for anonymous servers. Missing
environment references and malformed settings fail closed. Tokens are shared by
MCP and REST, never logged. REST uses the MCP URL's origin, not its path prefix.
Both clients send `X-Librarian-Project` with the canonical host project CWD.

### Legacy settings cleanup

Exactly these obsolete top-level `observational_memory` keys are accepted and
discarded: `storage`, `model`, `observer`, `reflector`, `pools`. Their values are
ignored, not validated or migrated, even if null, scalar or malformed. Removing
them is optional when a valid `librarian.url` is configured; that URL is still
required, and unrelated unknown keys and invalid Librarian settings still fail.
Registration emits one aggregate warning naming only the obsolete keys present
and explaining Librarian ownership. Repeated settings reads and jobs do not emit
migration warnings. There are no automatic configuration writes and no local
storage or processing fallback.

Linked Git worktrees are excluded completely: no registration, remote calls,
commands, memory tools or prompt additions, and no primary-checkout redirection.
Uncertain Git identity fails closed. Children and forks cannot observe, retrieve
or project memory even if explicitly allowlisted. A snapshot prepared for a fork
still uses the parent's identity and may project that parent's memory.

## Boundaries and compaction

Completed/cancelled `agent_end` and final non-retryable/exhausted
`llm_step_failed` events dispatch the existing extension-agent job. Its worker
sends only `{contract_version: 1, source_kind: hatfield, session_id,
parent_session_id: null, end_seq, terminal_status}` to `POST /api/om/v1/observe`,
expecting HTTP 202 with `{ok: true, contract_version: 1, status: queued}`.
No transcript, prompts or memory content are uploaded. Dispatch/network errors
are best effort and privacy-safe logged. There is no extension retry, outbox or
watermark; a later boundary signals its latest sequence so the server can catch
up. A lost final signal without a later boundary has no eventual-delivery guarantee.

Before compaction, `GET /api/om/v1/sessions/hatfield/{encodedId}/projection`
returns `{ok: true, contract_version: 1, text}`. Nonempty text replaces the summary;
empty/malformed/failed/timed-out responses continue normal compaction, never
cancel it. Responses are capped at 1 MiB with an absolute duration bound and
redirects disabled. There are no observer or queue waits.

## Retrieval and commands

`memory_search query [after] [before] [limit]` retains the existing project-scoped
MCP search contract. `recall {id}` accepts a lowercase 12–64 character hex ID only;
the matched memory determines its evidence session. Librarian reads evidence from
the canonical journal. MCP lazily calls `ensure_project` once per connection,
without waiting for discovery/index readiness. Failure is explicit, not local
fallback. Cancellation and tool deadline handling remain cooperative.

`/om-view` displays the current session's remote projection, resolving the live
session at invocation. `/om-status` and the local activity poller are removed.

## Ownership cutover

Do not deploy the thin-client cutover until all gates are verified: Librarian OM
is merged and deployed; fresh projects are discoverable without `om.sqlite`;
legacy import and single-writer ownership are coordinated safely; and backup
restore drills pass. Preparing this unmerged checkout does not switch live memory
writers or import data. Preserve existing SQLite files for the coordinated import;
this extension does not remove them. Local processing settings are no longer
used; only the five top-level legacy keys listed above are accepted and discarded.
Other obsolete or unknown settings are rejected.
