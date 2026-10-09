# ChatGPT subscription migration assessment

Status: assessment and proposed boundaries, not an approved public API or an implemented migration. Sources inspected on 2026-10-09. No authenticated provider requests, credential changes, or session migrations have been performed.

## Scope and baseline

The target is `ineersa/symfony-ai-openai-chatgpt-platform`, using direct-token OAuth and HTTP/SSE at `https://api.openai.com/v1/responses`. This is a different grant and service from the legacy `chatgpt.com/backend-api/codex/responses` transport. Existing Codex credentials must not be repurposed without evidence that the grant authorizes the new resource.

The task checkout starts at `agent-core` revision `d0f13d86d`. Pi was inspected at `6fb2e7815`. Installed Symfony AI OpenResponses and Platform packages are v0.14.0. The installed Codex package is pinned to `7ae521f9269ddb365013fd0943a25ac2d22c8727`; its separate local checkout is at `27e6d2b`. Do not implement against the older checkout and accidentally lose the installed cancellation fixes.

Finalized constraints:

- Use HTTP/SSE only for the new integration initially.
- Keep existing sessions and credentials intact during assessment and validation.
- Add no hard whole-response deadline. Keep cancellation responsive.
- Keep the legacy WebSocket cache idle expiry at 60 seconds wherever the legacy path remains in use. Worker-local caching is an accepted tradeoff.
- Reuse framework facilities. Do not port the whole TypeScript devkit or the old WebSocket stack.
- Finalize provider identifiers, auth command behavior, credential migration, and legacy disposition before implementation.

## What the official integration requires

### OAuth and identity

The [registration and sign-in guide](https://developers.openai.com/siwc/token-sharing-open-source/sign-in) specifies a public-client authorization-code flow:

1. Persist a stable host identity, such as `urn:uuid:<installation UUID>`, before first login. Keep it separate from the issued OAuth client ID.
2. Start the loopback listener before launching the browser. Keep `127.0.0.1` and `/auth/callback`; a later authorization may use a different available port. Use the exact same redirect URI for authorization and code exchange.
3. Generate fresh state, nonce, and PKCE S256 values for each attempt.
4. Use `dynamic_agent_client` only for a new registration. Save the callback's issued client ID. Reauthorize an existing registration with that issued ID, rather than creating another registration every time.
5. Request `openid profile email offline_access resource.invoke chatgpt.tokens.use.direct`, with resource `https://api.openai.com/v1`.
6. Validate callback state even when handling an OAuth error. On reauthorization, retain the selected registration's client ID if the callback omits it; reject a different returned client ID.
7. Exchange the code without a client secret at `https://auth.openai.com/api/accounts/oauth/token`.
8. Verify the ID-token signature against OpenAI JWKS. Validate issuer, audience against the issued client ID, expiry, and nonce. On reauthorization, require the verified identity to match the selected registration.
9. Check the granted token-response scopes for `chatgpt.tokens.use.direct` before inference. An identity-only login does not grant plan usage.
10. Persist the verified identity, issued client ID, ID token, granted scopes, access/refresh tokens, and expiry in protected storage. Use atomic writes and owner-only permissions.

The [accounts and sessions guide](https://developers.openai.com/siwc/token-sharing-open-source/profiles-and-sessions) specifies refresh with the saved issued client ID and resource. Serialize refreshes across workers, recheck credentials under the lock, and persist rotated fields together. Cancellation must not leave a successful token rotation unpersisted. Disconnect needs explicit remote-revocation handling and local cleanup; process exit is not disconnect.

Use existing League OAuth and Symfony Lock, Filesystem, HttpClient, Console, Process, and UID facilities where suitable. ID-token verification needs a maintained JOSE/OIDC implementation; do not implement signature verification by hand or treat decoding a JWT as verification. Its dependency and public contract still need selection.

### Profiles and usage

The [devkit local client](https://github.com/openai/sign-in-with-chatgpt-devkit/blob/main/packages/local/src/index.ts) stores profiles and active-profile selection locally. Its profile methods are not a remote profile API. Registration identity must remain correct even if the first CLI interface exposes only one active account. A multi-account picker is a separate product decision.

[Cookbook section 6](https://developers.openai.com/cookbook/articles/sign-in-with-chatgpt#6-help-users-manage-their-usage) recommends identifying plan-backed requests and offering a Manage usage action at `https://chatgpt.com/settings/usage`, especially on usage-limit errors. The inspected devkit does not expose a numerical quota-fetch method. Response token accounting is not remaining plan quota.

The current host [quota probe](../src/CodingAgent/Infrastructure/ProviderQuota/ProviderQuotaProbeService.php) uses the legacy `https://chatgpt.com/backend-api/wham/usage` endpoint. Do not send new direct-token credentials there or assume its numerical report transfers to the new integration. Decide how the new provider appears in `/usage`; the documented management URL is the supported initial alternative.

### Model discovery and inference

The [models and inference guide](https://developers.openai.com/siwc/token-sharing-open-source/models-and-inference) specifies authenticated `GET /v1/models`. The [devkit model client](https://github.com/openai/sign-in-with-chatgpt-devkit/blob/main/packages/local/src/models.ts) expects a `models` array, filters `visibility === "list"`, displays `display_name`, and sends `slug` as the inference model. Do not assume the ordinary API-key catalog's `data[].id` shape or that a static catalog proves account entitlement. Discovery should follow the active account.

The [preview limitations](https://developers.openai.com/siwc/token-sharing-open-source/preview-limitations#responses-api-requirements) define the request profile:

- Set `store: false` and `stream: true`; send the required history in an `input` array.
- Use `instructions` or developer messages. Explicit system-message input items are rejected.
- Omit HTTP `previous_response_id`. There is no persistent server-side conversation baseline for this path.
- Omit `background`, `conversation`, `max_output_tokens`, `max_tool_calls`, `metadata`, `moderation`, `multi_agent`, `prompt`, `prompt_cache_retention`, `safety_identifier`, `temperature`, `top_logprobs`, `top_p`, `truncation`, and `user`.
- Group function/custom tools in namespaces or provide them through `additional_tools` input items. The plain top-level function list produced by the generic bridge needs adaptation.
- Do not emit Responses `tool_search`, hosted MCP/connectors, file search, Code Interpreter, image generation, native computer use, or top-level `programmatic_tool_calling` on this route. Local tool execution and local child agents are distinct from hosted tools.
- Treat a response as successful only after its terminal success event. Never execute unfinished tool calls from a partial stream.

Text, images, and file inputs have model-dependent support; the Files upload API and audio/video interfaces are outside this grant. The first implementation must match actual Hatfield inputs and local tools rather than infer support from the devkit's text examples.

The [devkit Responses adapter](https://github.com/openai/sign-in-with-chatgpt-devkit/blob/main/packages/local/src/responses.ts) is a text-oriented adapter over the OpenAI SDK, not a replacement for a coding agent's tool/reasoning stream conversion.

## Pi as a reference, not the complete contract

Inspected source paths are relative to Pi revision `6fb2e7815`.

| Concern | Pi behavior | Consequence for this migration |
| --- | --- | --- |
| OAuth | `packages/ai/src/auth/oauth/openai-chatgpt.ts` uses PKCE, state, nonce, the direct-token scope, resource, and issued callback client ID. | Reuse the protocol understanding, not a literal port. |
| Reauthorization | The authorize URL always uses `dynamic_agent_client`; refresh uses the stored issued client ID. | Implement the documented existing-registration reauthorization separately. |
| ID token | Lines 200–203 only check presence; the token is discarded and nonce is not verified. | Follow official signature/claim validation instead. |
| Installation ID | `settings-manager.ts::getOrCreateDeviceId()` persists a global UUID; the app supplies it through login options. | Package owns the protocol; host supplies a stable installation identity. |
| Accounts | Credentials are keyed by provider. No multi-account selection or remote revocation in the inspected path. | Do not assume Pi supplies the devkit's account lifecycle. |
| Models | `providers/openai.ts` uses a generated catalog and only excludes classifiers for OAuth. | Add account-aware entitlement discovery; static GPT-6.1 Sol membership is not live proof. |
| Usage | `api/openai-responses.ts` adds the usage-settings URL on subscription-limit errors. No quota-fetch API was found. | Keep response accounting separate from plan quota. |
| Refresh | `auth/resolve.ts` refreshes under a credential-store lock and rechecks expiry. | Preserve the concurrency and rotated-token invariant. |
| Requests | `api/openai-responses.ts` sets `store:false` and `stream:true` and omits selected unsupported fields for ChatGPT credentials. | Apply the full current official restriction list, not only Pi's list. |
| Replay and stream | `api/openai-responses-shared.ts` retains encrypted reasoning and paired tool IDs, rejects missing terminal events and unfinished tool calls. | Preserve those behavioral guarantees using framework facilities where possible. |

## Framework reuse and old fixes

The installed Symfony AI OpenResponses bridge is already used by other host providers. Its [factory](../vendor/symfony/ai-open-responses-platform/Factory.php) accepts an injected client, model catalog, contract, and event dispatcher.

| Behavior | Existing facility | Proposed treatment |
| --- | --- | --- |
| SSE framing, including absent content type | `Symfony\AI\Platform\Result\Stream\RawSseStream` | Reuse. Do not copy the old parser. |
| Tool/reasoning stream conversion, token accounting, typed errors, interrupted streams | OpenResponses `ResultConverter` | Reuse and prove against the new endpoint's events. |
| Encrypted reasoning replay | OpenResponses `ResultConverter` and `AssistantMessageNormalizer` | Reuse. Request encrypted reasoning when needed for stateless history. |
| System guidance | OpenResponses `MessageBagNormalizer` puts it in `instructions` | Reuse; this matches the documented restriction. |
| Empty tool arguments encoded as `{}` | OpenResponses `ToolCallNormalizer` | Reuse. |
| Structured output | OpenResponses `ModelClient::createBody()` maps `response_format` to `text.format` | Reuse if the selected model/grant accepts the format. |
| HTTP cancellation | Host `LlmPlatformAdapter::abortConnection()` cancels `RawHttpResult`'s response; provider factory supplies a cancel-aware HTTP client | Reuse and prove cancellation while the stream is silent. No new WebSocket abort interface. |
| Direct-token auth, refresh, registration storage and command | Not provided by OpenResponses | New package owns the flow-specific lifecycle. Reuse existing framework mechanisms. |
| Request restrictions, namespace grouping and internal option exclusion | Not supplied by generic `ModelClient::createBody()` | Add the smallest evidenced request adaptation; preserve local tool names and result identity. |
| Old composite tool IDs and strict/null tool schema workaround | Old Codex normalizers differ from OpenResponses | Establish whether the new route needs either before carrying them over. |
| Old `configuration_update` reasoning transitions | Codex-specific contract and host hooks | No blind port. Verify current model requirements and saved-history behavior. |
| Cached continuation, WS headers, lease/age cleanup, service-restart handling | Old WebSocket stack | Do not add to the HTTP-only package. |

### Host integration risks

1. [LlmHttpClientOptions](../src/CodingAgent/Infrastructure/SymfonyAi/Http/LlmHttpClientOptions.php) defaults to 30 seconds idle and **120 seconds total duration**. [SymfonyAiProviderFactory](../src/CodingAgent/Infrastructure/SymfonyAi/SymfonyAiProviderFactory.php) applies these to its injected HTTP client. Moving to HTTP must not inherit that total cap silently. Preserve the agreed no-total-deadline behavior for normal ChatGPT generation without changing unrelated providers or explicit operation budgets inadvertently.
2. [SessionAwareModelResolver](../src/CodingAgent/Agent/Execution/SessionAwareModelResolver.php) restricts session prompt-cache keys and several transition/continuation markers to provider type `codex`. Preserve stable cache identity if the new route supports it; do not leak legacy internal markers onto the wire.
3. Grok auth imports the old package's generic OAuth provider, browser launcher, loopback callback server, and manual-code parser. Removing the old dependency without relocating or retaining these facilities breaks Grok. Its disposition must be explicit, not an accidental consequence of migration.
4. The [Codex provider builder](../src/CodingAgent/Infrastructure/SymfonyAi/Codex/CodexSymfonyAiProviderBuilder.php) refreshes bearer credentials through a closure and projects Hatfield YAML models. The new builder must reconcile account discovery with YAML metadata without exposing unsupported or unauthorized models.
5. Preserve existing local transcript and tool identities. Reauthentication is not authority to rewrite session archives or switch running workers to a different credential.

## Proposed package and host split

This split is a proposal, not authorization to add its public API.

- **New package:** direct-token public OAuth, verified registration records, safe locked refresh, remote disconnect, model-discovery protocol, an injectable Console auth interface, and a narrow OpenResponses adapter for the documented request profile. Use HttpClient and OpenResponses streaming/conversion rather than a second parser. Exclude legacy WS dependencies and backend endpoints.
- **Host:** credential path and lock wiring, installation identity and app name, provider/model settings, session cache identity, local tool dispatch, cancellation policy, account/usage presentation, packaging, and the user-approved legacy migration.
- **Framework:** serialization, request transport, SSE parsing, stream conversion, reasoning signatures, token accounting, and typed HTTP failures where already supported.

## Decisions needed before implementation

1. Add a distinct ChatGPT provider and auth command for explicit opt-in first, or replace the existing Codex provider immediately? A distinct opt-in path is the safer assessment recommendation; it is not an implicit requirement to keep both forever.
2. Expose one saved account initially, or implement profile list/select/add/disconnect now? Correct registration identity and issued-client-ID reuse are required either way.
3. How should `/usage` present the new provider when only the management link is documented? Recommendation: identify plan-backed use and show the management URL, without claiming numerical quota.
4. How should account-specific discovery interact with the existing YAML catalog? Finalize model selection, refresh timing, and failure behavior. Do not use a static-catalog fallback to claim entitlement.
5. Select package namespace, supported Symfony AI version range, auth/storage interfaces, and the maintained ID-token validation dependency. Scope shared OAuth helpers needed by Grok before removing the legacy package.

## Implementation sequence and proof

After those decisions are finalized, main assigns bounded implementation slices with the workflow ownership log:

1. One package fork implements the approved OAuth/registration lifecycle and its isolated tests in the new package checkout. The repository currently has no commits; establish its branch/worktree baseline before assigning a writer.
2. Resume that package owner for the dependent model-discovery and HTTP/SSE bridge slice. Prove forbidden-field handling, instruction placement, tool grouping and round trips, encrypted reasoning replay, terminal-event handling, limit errors, and cancellation. Validate redacted diagnostics and redirect/retry behavior.
3. A host integration fork uses the agent-core task worktree after the package contracts are stable. It wires credentials, settings, provider routing, model discovery, usage presentation, no-total-deadline behavior, cache identity, and packaging. Migrate Grok helper dependencies only within the approved legacy disposition.
4. Run focused Castor validation in each exact checkout. Include deterministic identity/signature/nonce failures, callback errors, refresh races, reauthorization client mismatch, revocation failure, malformed model lists, partial streams, unfinished tools, and secret-free logs. Use existing test isolation; never use real session archives as fixtures.
5. Obtain approval for fresh login and live provider proof. Verify model entitlement, local tool execution, reasoning/history replay, and cancellation on an actual signed-in account. Mocked tests cannot establish grant eligibility or server acceptance.
6. Leave the task IN-PROGRESS until implementation and required proof are complete. The CODE-REVIEW transition owns the full Castor gate and independent review. This assessment alone is not task completion.
