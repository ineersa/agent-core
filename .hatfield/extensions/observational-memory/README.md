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

Linked Git worktree targets are excluded: no remote calls,
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

## Session deletion

After permanent local session deletion succeeds, the extension sends a synchronous, bounded `DELETE /api/om/v1/sessions/hatfield/{encodedId}` with no body, using the captured session's canonical CWD as `X-Librarian-Project` and the shared optional token. HTTP 200 with `{ok: true, contract_version: 1, deleted: boolean}` is success whether `deleted` is true or false. Captured children and linked-worktree targets are excluded; the currently attached session is never substituted. The deletion hook remains registered even in an excluded active checkout so an eligible target is evaluated using its own project.

Cleanup is best effort: remote failure is logged without content or credentials and never reverses local deletion. There is no retry/outbox and no eventual-cleanup guarantee; unavailable Librarian can retain memory indefinitely. Late observe requests may recreate server-side session rows; preventing this is a Librarian-side responsibility, not guaranteed by this client.

## Claude Code plugin

The same package is a Claude Code plugin, so Claude Code sessions build and use
the same Librarian memory as Hatfield sessions. It reads the Librarian settings
above from the same files (user `~/.hatfield/settings.yaml` < project
`.hatfield/settings.yaml`); the plugin manifest holds no URL or token.

```text
/plugin marketplace add ineersa/agent-core
/plugin install observational-memory@hatfield
```

Requirements: `php` (8.5+, with the `curl` extension) and `composer` on
`PATH`. The first start of each plugin version installs Composer dependencies
into its own directory under the plugin data directory, so that start can take
a minute; reconnect with `/mcp` if it timed out. Sessions still running an
older version keep using its installation; installations of versions Claude
Code has removed are deleted at the next install.

- **Memory generation:** async `Stop` and `SessionEnd` hooks send Librarian a
  content-free `claude_code` observe signal: the session id and the number of
  complete transcript lines. The count is incremental: a per-session cursor in
  the plugin data directory means each turn reads only the bytes appended since
  the previous one. `SessionEnd` removes the cursor, and cursors of sessions
  that ended without it are pruned after 30 days. Librarian reads the transcript itself and skips
  subagent (sidechain), meta and compact-summary records. The `Stop` signal
  covers every completed turn. `SessionEnd` hooks share a short budget, so the
  final signal is best effort. Hooks never block or fail the session; failures
  go to Claude Code's debug log.
- **Retrieval:** the `observational-memory` MCP server exposes exactly
  `memory_search` and `recall`, with the Hatfield schemas and results.
  Expected failures, including invalid arguments, upstream tool errors,
  cancellation, and timeouts, retain their existing result fields and are
  returned as MCP tool errors. Unexpected failures are MCP tool errors with
  the originating exception class and message (`Class: message`).
- **Compaction:** Claude Code's own compaction is unchanged; there is no
  projection or `/om-view`.

Librarian locates a transcript as
`<CLAUDE_PROJECTS_PATH>/<project root with every non-alphanumeric character replaced by '-'>/<session id>.jsonl`.
As in Claude Code, a character outside the Basic Multilingual Plane (such as an
emoji) becomes two dashes, because Claude Code replaces UTF-16 code units.
The project root is the directory Claude Code was started in
(`CLAUDE_PROJECT_DIR`), resolved through symlinks and sent as
`X-Librarian-Project`. It must be the Git checkout root (or a directory outside
Git): a session started in a subdirectory gets no memory tools and is not
observed, so it never registers a stray subdirectory project. Hooks also do
nothing when Librarian is not configured or in linked Git worktrees. If the
transcript is not where Librarian will look (for example, a symlinked start
directory), the hook logs `om.claude.transcript_path_mismatch` instead.

Set up:

- **Librarian:** deploy a Librarian that reads Claude Code transcripts and
  mount the Claude projects directory (`~/.claude/projects`) into it as
  `CLAUDE_PROJECTS_PATH`.
- **Turn off Claude's built-in auto memory** so it does not compete with
  Librarian: set `"autoMemoryEnabled": false` in the project
  `.claude/settings.json` (or user settings), or export
  `CLAUDE_CODE_DISABLE_AUTO_MEMORY=1`.
- **Keep transcripts as long as their memory:** raise `cleanupPeriodDays` in
  Claude Code settings. Once Claude Code deletes a transcript, `recall` for its
  memories returns `session_deleted`.
- Start Claude Code in the checkout root. The server starts once per session;
  after changing settings, reconnect it with `/mcp`.
