# Hatfield integration for OpenAI Codex provider

## Goal
## Summary

Integrate the Symfony AI OpenAICodex bridge (built in task `symfony-ai-openai-codex-bridge`) into Hatfield: OAuth PKCE service, provider config, factory wiring, CLI auth command, and settings.

**Detailed plan**: `.pi/plans/openai-codex-bridge.md` (Task 2 section)

**Depends on**: Task `symfony-ai-openai-codex-bridge` must be complete.

## Files to create

```
src/CodingAgent/Auth/
├── OpenAICodexOAuthService.php      ← PKCE flow, token refresh, account ID extraction
├── OAuthCredentials.php             ← DTO: access_token, refresh_token, expires_at, account_id
└── OAuthCredentialsStorage.php      ← Read/write ~/.hatfield/auth.json

src/CodingAgent/CLI/
└── CodexAuthCommand.php             ← bin/console auth:codex
```

## Files to modify

```
src/CodingAgent/Config/Ai/AiProviderConfig.php  ← Handle type: 'codex'
src/CodingAgent/Infrastructure/SymfonyAi/SymfonyAiProviderFactory.php ← Build Codex provider
src/CodingAgent/Config/ReasoningOptionsResolver.php ← Responses API reasoning.effort format
config/hatfield.defaults.yaml ← Codex provider config with models
config/services.yaml ← Register OAuthService, AuthCommand
depfile.yaml ← CodingAgent → OpenAICodexPlatform allowed
```

## OAuth PKCE flow (from pi-mono research)

1. Generate PKCE: `code_verifier` (32 random bytes, base64url) + `code_challenge` (SHA-256 of verifier, base64url)
2. Start local HTTP server on port 1455
3. Open browser to `auth.openai.com/oauth/authorize` with params:
   - `client_id=app_EMoamEEZ73f0CkXaXp7hrann`
   - `redirect_uri=http://localhost:1455/auth/callback`
   - `scope=openid profile email offline_access`
   - `code_challenge={challenge}&code_challenge_method=S256`
   - `codex_cli_simplified_flow=true`
   - `id_token_add_organizations=true`
   - `originator=hatfield`
4. Exchange authorization code for tokens: POST `auth.openai.com/oauth/token` with `grant_type=authorization_code`
5. Extract `chatgpt_account_id` from JWT payload: `payload["https://api.openai.com/auth"].chatgpt_account_id`
6. Store: `~/.hatfield/auth.json` → `{ "openai-codex": { access_token, refresh_token, expires_at, account_id } }`
7. Refresh: POST with `grant_type=refresh_token`

## Provider config (hatfield.defaults.yaml)

```yaml
openai-codex:
    type: codex
    enabled: true
    supports_thinking_levels: true
    compatibility:
        supports_developer_role: false
    models:
        gpt-5.4:
            reasoning: true
            thinking_level_map: { minimal: low, xhigh: xhigh }
            tool_calling: true
            input: [text, image]
            context_window: 272000
            max_tokens: 128000
            cost: { input: 2.50, output: 15 }
        gpt-5.4-mini:
            reasoning: true
            thinking_level_map: { minimal: low, xhigh: xhigh }
            tool_calling: true
            input: [text, image]
            context_window: 272000
            max_tokens: 128000
            cost: { input: 0.75, output: 4.50 }
        gpt-5.5:
            reasoning: true
            thinking_level_map: { minimal: low, xhigh: xhigh }
            tool_calling: true
            input: [text, image]
            context_window: 272000
            max_tokens: 128000
            cost: { input: 5.00, output: 30 }
```

## Reasoning options

Codex uses the Responses API reasoning format: `{ "reasoning": { "effort": "high", "summary": "auto" } }`. Add this format to `ReasoningOptionsResolver` alongside existing `zai` and `reasoning_effort` formats.

## Factory wiring

`SymfonyAiProviderFactory::buildProvider()` branches on `type: codex`:
- Gets valid OAuth credentials (refreshing if expired)
- Calls `\Symfony\AI\Platform\Bridge\OpenAICodex\Factory::createProvider()` with access token + account ID

## Validation

- `castor check` passes (deptrac + phpstan + cs-check + tests)
- `bin/console auth:codex` initiates OAuth flow
- Codex provider appears in model list
- E2E test with real Codex API (manual, gated on having ChatGPT subscription)

## Acceptance criteria
- OAuth PKCE flow works: bin/console auth:codex opens browser, captures callback, stores credentials in ~/.hatfield/auth.json
- Token refresh works: expired tokens are refreshed transparently before requests
- SymfonyAiProviderFactory builds Codex provider using OpenAICodex bridge with OAuth credentials
- ReasoningOptionsResolver emits reasoning.effort format for Codex models
- Codex provider config in hatfield.defaults.yaml with gpt-5.4, gpt-5.4-mini, gpt-5.5 models
- Deptrac allows CodingAgent → OpenAICodexPlatform but forbids reverse
- castor check passes (deptrac + phpstan + cs-check + tests)
- credentials storage handles missing file, expired tokens, and invalid JSON gracefully

## Workflow metadata
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-06-05T22:55:55.377Z
