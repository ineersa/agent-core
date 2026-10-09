# ChatGPT subscription migration

## Approved scope

Replace the legacy Codex implementation with the single-account ChatGPT direct-token OAuth integration. The runtime provider type is `chatgpt`, and the authentication command is `auth:chatgpt`. There is no parallel legacy transport or old-type alias.

Keep `openai-codex` as the bundled provider identifier so existing model references and session history retain their identities. The identifier is opaque; it no longer selects the Codex backend. Keep the YAML model catalog initially. A configured model is a request target, not proof of account entitlement.

The Composer package owns public-client OAuth, dynamic registration, PKCE, cryptographic ID-token verification, issued-client-ID reauthorization, installation identity, protected storage, locked refresh, and remote disconnect. Hatfield supplies its application name, storage path, Lock factory, and HTTP client. ChatGPT credentials use `~/.hatfield/chatgpt-auth.json`; old credentials in `~/.hatfield/auth.json` are neither converted nor consumed. Grok retains its own grant and uses the new package's provider-neutral OAuth helpers.

The package reuses Symfony AI OpenResponses 0.14 for serialization, SSE conversion, reasoning signatures, and typed errors. Requests use `https://api.openai.com/v1/responses`, full history, `store: false`, and `stream: true`. Local tools use developer `additional_tools` items without renaming dispatch functions. Unsupported fields and internal host markers are omitted. Paired tool identities and encrypted reasoning survive round trips.

There are no WebSockets, cached continuation, SSE fallback, or inline authentication replay. Supported GPT-6 chat models retain their initial reasoning baseline and emit durable native HTTP `configuration_update` items on effort switches. ChatGPT generation uses a 300-second idle limit and no total deadline. Other providers retain their HTTP defaults. Completed tool calls remain dispatchable; interrupted argument streams do not become synthetic complete tools. `/usage` shows the ChatGPT usage-management link and session accounting, not a numerical subscription quota. z.ai quota presentation is unchanged. Permanent subscription limits are not retried.

## Pinned development dependency

This worktree uses the public GitHub Composer VCS repository:

- Package: `ineersa/symfony-ai-openai-chatgpt-platform`.
- Constraint: `dev-task/chatgpt-subscription-http#66acf01ace62bac98dd755ef7778e8c9b677e4c0`.
- Source: [ChatGPT platform repository](https://github.com/ineersa/symfony-ai-openai-chatgpt-platform).
- Package review: [pull request 1](https://github.com/ineersa/symfony-ai-openai-chatgpt-platform/pull/1).

The locked source commit is not yet published. This worktree installs it from the package checkout using a command-scoped Git URL rewrite; Composer still records the public repository URL and exact commit. Publish the approved package commit before running fresh remote installations or CI. No absolute path repository or global Git configuration is committed. The package has no stable release tag yet.

## Manual validation

These commands create a new ChatGPT grant in the separate auth file. They do not migrate the old grant. Login and provider inference require the user's participation; automated tests use synthetic credentials and mocked streams.

1. Enter the application worktree:

	```console
	cd /home/ineersa/projects/agent-core-worktrees/2026-10-08-migrate-chatgpt-subscription-access-to-openai-responses-api
	```

2. Log in through this checkout:

	```console
	php bin/console auth:chatgpt login
	```

	For manual callback entry, use `php bin/console auth:chatgpt login --manual --no-browser` and paste the complete matching callback URL.

3. Run `castor run:agent` from this worktree. Select `openai-codex/gpt-6.1-sol` or another configured model available to the account.
4. Exercise a local file-read tool and its follow-up response. Check that reasoning and tool results replay on the next turn.
5. Cancel a response while waiting and while streaming. Check that cancellation returns control without launching unfinished tools.
6. Run `/usage`. Expect the ChatGPT management URL, session accounting, and any configured z.ai quota section. Do not expect a numeric ChatGPT quota.

The tracked project override selects `type: chatgpt` and the new reasoning format even when the home catalog predates this migration. For other projects, update their catalog and replace explicit `type: codex` overrides with `type: chatgpt`; remove `transport` and WebSocket-cache fields. Keep `supports_reasoning_configuration_updates` for supported models and providers. Do not rewrite session archives or provider-qualified model IDs. Explicit old types are unsupported, not silently rerouted.

## Proof boundaries

Automated host proof covers fresh provider construction without a grant, real host/package tool and encrypted-reasoning replay through mocked HTTP, forbidden fields, interrupted unfinished tools, permanent usage errors, durable child cache identity, HTTP cancellation, Grok credential preservation, and virtual `/usage` routing/rendering. It does not establish real account eligibility, model access, OAuth server acceptance, or a successful live model turn.

HTTP and terminal SSE subscription-policy and quota errors are nonretryable even with an available retry budget. Generic authentication and bad-request retry behavior is unchanged. An invalid subscriber context does not trigger an automatic OAuth loop; terminal refresh-token failure is handled by the package under its storage lock.

Native reasoning, tool-call slots, and signed assistant messages survive canonical JSONL save and cold reconstruction into the next mocked HTTP request. Tests retain empty reasoning summaries, encrypted identities, distinct identical-text message boundaries, commentary/final phases, and empty signed messages. Text signatures are stripped for a foreign target model. Kernel-backed mocked HTTP proof covers low/high/low updates, cold ORM reload, stable cache keys, explicit overrides, independent child keys, and rewritten-history epochs. Full session-rebuilder and persisted fork-topology proof remain outstanding; live subscription-endpoint acceptance is unverified.

The task remains in progress until manual account validation, independent review, and the workflow's full Castor gate complete. No real credentials, production workers, or session archives are test fixtures.

References: [Sign in with ChatGPT](https://developers.openai.com/siwc/token-sharing-open-source/sign-in), [preview request restrictions](https://developers.openai.com/siwc/token-sharing-open-source/preview-limitations), and [models and inference](https://developers.openai.com/siwc/token-sharing-open-source/models-and-inference).
