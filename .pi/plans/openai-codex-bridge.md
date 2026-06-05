# OpenAI Codex Platform Bridge — Implementation Plan

## Overview

Build a new Symfony AI platform bridge (`OpenAICodex`) that enables access to OpenAI's Codex models via the ChatGPT backend. This is a two-task effort:

1. **Task 1**: Build the platform bridge inside agent-core (Symfony AI namespace, deptrac-isolated)
2. **Task 2**: Integrate into Hatfield (OAuth PKCE, provider config, factory wiring, settings)

The bridge targets the OpenAI Codex SSE endpoint at `chatgpt.com/backend-api/codex/responses` using the Responses API format. WebSocket transport is deferred to a future iteration.

---

## Background: How Codex Works

### API Differences from Standard OpenAI

| Aspect | Standard OpenAI | Codex |
|--------|----------------|-------|
| **Base URL** | `api.openai.com/v1` | `chatgpt.com/backend-api` |
| **Endpoint** | `/v1/responses` | `/codex/responses` |
| **Auth** | API key (`Authorization: Bearer sk-...`) | OAuth access token + `chatgpt-account-id` header |
| **Auth source** | Environment variable | OAuth PKCE flow (`auth.openai.com`) |
| **Extra headers** | None | `chatgpt-account-id`, `originator`, `OpenAI-Beta: responses=experimental` |
| **Request format** | Responses API (`input[]`, `instructions`) | Same — Responses API |
| **Response format** | SSE: `response.output_text.delta`, etc. | Same SSE events |
| **Subscription** | Pay-per-use API billing | ChatGPT Plus/Pro subscription (server-side enforcement) |
| **Models** | gpt-4o, gpt-4.1, etc. | gpt-5.2, gpt-5.3-codex, gpt-5.4, gpt-5.4-mini, gpt-5.5 |

### Available Models (from pi-mono research)

| Model ID | Context | Max Tokens | Input Cost | Output Cost | Input Types | Thinking Levels |
|----------|---------|------------|------------|-------------|-------------|----------------|
| `gpt-5.2` | 272K | 128K | $1.75/1M | $14/1M | text, image | xhigh, minimal→low |
| `gpt-5.3-codex` | 272K | 128K | $1.75/1M | $14/1M | text, image | xhigh, minimal→low |
| `gpt-5.3-codex-spark` | 272K | 128K | $1.75/1M | $14/1M | text only | xhigh, minimal→low |
| `gpt-5.4` | 272K | 128K | $2.50/1M | $15/1M | text, image | xhigh, minimal→low |
| `gpt-5.4-mini` | 272K | 128K | $0.75/1M | $4.50/1M | text, image | xhigh, minimal→low |
| `gpt-5.5` | 272K | 128K | $5.00/1M | $30/1M | text, image | xhigh, minimal→low |

### OAuth PKCE Flow (from pi-mono `packages/ai/src/utils/oauth/openai-codex.ts`)

```
1. Generate PKCE: code_verifier (32 random bytes, base64url) + code_challenge (SHA-256 of verifier, base64url)
2. Build authorization URL:
   - auth.openai.com/oauth/authorize
   - response_type=code
   - client_id=app_EMoamEEZ73f0CkXaXp7hrann
   - redirect_uri=http://localhost:{port}/auth/callback
   - scope=openid profile email offline_access
   - code_challenge={challenge}
   - code_challenge_method=S256
   - state={random}
   - codex_cli_simplified_flow=true
   - id_token_add_organizations=true
   - originator=hatfield
3. Open browser → user logs in → callback with ?code=...&state=...
4. Exchange code for tokens:
   POST auth.openai.com/oauth/token
   grant_type=authorization_code
   client_id=app_EMoamEEZ73f0CkXaXp7hrann
   code={code}
   code_verifier={verifier}
   redirect_uri=http://localhost:{port}/auth/callback
   → Returns: { access_token, refresh_token, expires_in, id_token }
5. Extract account_id from JWT access_token:
   Decode JWT payload → payload["https://api.openai.com/auth"].chatgpt_account_id
6. Store credentials (access_token, refresh_token, expires_at, account_id)
7. Refresh tokens before expiry:
   POST auth.openai.com/oauth/token
   grant_type=refresh_token
   refresh_token={refresh_token}
   client_id=app_EMoamEEZ73f0CkXaXp7hrann
```

### Request Headers (from pi-mono `openai-codex-responses.ts`)

```http
Authorization: Bearer {access_token}
chatgpt-account-id: {account_id}
originator: hatfield
OpenAI-Beta: responses=experimental
Content-Type: application/json
User-Agent: hatfield ({os} ...)
accept: text/event-stream
```

### SSE Response Events (same as OpenAI Responses API)

```
event: response.output_text.delta
data: {"type":"response.output_text.delta","delta":"Hello","output_index":0,...}

event: response.reasoning_summary_text.delta
data: {"type":"response.reasoning_summary_text.delta","delta":"Let me think...","output_index":0}

event: response.reasoning_summary_text.done
data: {"type":"response.reasoning_summary_text.done","text":"Let me think..."}

event: response.completed
data: {"type":"response.completed","response":{"usage":{...},"output":[...]}}
```

### Error Handling (from pi-mono)

- `401` → Authentication error → attempt token refresh
- `429` with `usage_limit_reached` / `usage_not_included` → subscription limit hit
- `429` with `rate_limit_exceeded` → temporary, retry with backoff
- `400` → Bad request (invalid model, etc.)
- Server errors (5xx) → retry with exponential backoff

---

## Task 1: Symfony AI OpenAICodex Platform Bridge

### Namespace & Location

```
src/Platform/Bridge/OpenAICodex/
├── CodexModelClient.php
├── Factory.php
├── CodexModel.php
├── CodexModelCatalog.php
├── ResultConverter.php
├── TokenUsageExtractor.php
├── composer.json
├── phpunit.xml.dist
└── Tests/
    ├── CodexModelClientTest.php
    ├── ResultConverterTest.php
    └── CodexModelCatalogTest.php
```

Namespace: `Symfony\AI\Platform\Bridge\OpenAICodex`

### Dependencies

```json
{
    "require": {
        "php": "^8.3",
        "symfony/ai-platform": "^0.9",
        "symfony/http-client": "^7.3|^8.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.5"
    }
}
```

Note: Does NOT depend on `symfony/ai-open-responses-platform`. We implement our own `ResultConverter` and `Contract` since Codex has enough differences (streaming event names, error handling, thinking format) to justify independence. If we later find significant overlap, we can extract shared traits.

### Deptrac Configuration

Add to `depfile.yaml`:

```yaml
parameters:
  paths:
    - src
  exclude_file_patterns:
    # ...
  layers:
    - name: OpenAICodexPlatform
      collectors:
        - type: className
          regex: ^Symfony\\AI\\Platform\\Bridge\\OpenAICodex\\

  ruleset:
    OpenAICodexPlatform:
      - Symfony\AI\Platform  # core abstractions only
      - Symfony\Component\HttpClient
      - Symfony\Contracts\HttpClient
      # MUST NOT depend on AgentCore, CodingAgent, Tui, or any Hatfield code
```

### File Details

#### 1. `CodexModel.php`

Extends `Symfony\AI\Platform\Model` — same pattern as `ResponsesModel` in OpenResponses bridge.

```php
namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\AI\Platform\Model;

class CodexModel extends Model
{
    public function __construct(
        string $name = 'gpt-5.5',
        array $options = [],
    ) {
        parent::__construct($name, $options);
    }
}
```

#### 2. `CodexModelClient.php`

Custom HTTP client that adds Codex-specific headers. Follows the same pattern as `OpenResponses\ModelClient` but with:

- **Auth**: OAuth access token via `auth_bearer` (same mechanism — just the token source differs)
- **Extra headers**: `chatgpt-account-id`, `originator`, `OpenAI-Beta`
- **Path**: `/codex/responses` instead of `/v1/responses`
- **Account ID**: Constructor parameter (extracted from JWT at the Hatfield layer, not here)

```php
namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CodexModelClient implements ModelClientInterface
{
    private readonly EventSourceHttpClient $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $accessToken,
        private readonly string $accountId,
        private readonly string $path = '/codex/responses',
        private readonly string $originator = 'hatfield',
    ) {
        $this->httpClient = $httpClient instanceof EventSourceHttpClient
            ? $httpClient
            : new EventSourceHttpClient($httpClient);
    }

    public function supports(Model $model): bool
    {
        return $model instanceof CodexModel;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        $requestOptions = [
            'auth_bearer' => $this->accessToken,
            'headers' => [
                'Content-Type' => 'application/json',
                'chatgpt-account-id' => $this->accountId,
                'originator' => $this->originator,
                'OpenAI-Beta' => 'responses=experimental',
            ],
            'json' => array_merge($options, ['model' => $model->getName()], $payload),
        ];

        return new RawHttpResult(
            $this->httpClient->request('POST', $this->baseUrl.$this->path, $requestOptions)
        );
    }
}
```

**Key design decisions**:
- The bridge accepts `accessToken` and `accountId` as constructor parameters — it does NOT know about OAuth, PKCE, token refresh, or JWT decoding. Token lifecycle is the consumer's responsibility (Hatfield layer).
- The `originator` is configurable (defaulting to `hatfield`) so the bridge can be reused by other consumers.
- SSE streaming is handled by `EventSourceHttpClient` (same as all other Symfony AI bridges).

#### 3. `ResultConverter.php`

Handles Codex SSE stream → Symfony AI delta conversion. The Codex response format uses the same Responses API event types as OpenResponses, so the structure is similar.

```php
namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;

final class ResultConverter implements ResultConverterInterface
{
    public function supports(Model $model): bool
    {
        return $model instanceof CodexModel;
    }

    public function convert(RawResultInterface|RawHttpResult $result, array $options = []): ResultInterface
    {
        // Handle error status codes
        $response = $result->getObject();
        // 401 → AuthenticationException, 400 → BadRequestException, 429 → RateLimitExceededException
        // (same pattern as OpenResponses ResultConverter)

        if ($options['stream'] ?? false) {
            return new StreamResult($this->convertStream($result));
        }

        // Non-streaming: parse response.output[] → TextResult, ToolCallResult, ThinkingResult
        $data = $result->getData();
        // Extract output items, convert to result types
    }

    private function convertStream(RawResultInterface|RawHttpResult $result): \Generator
    {
        $currentThinking = null;

        foreach ($result->getDataStream() as $event) {
            $type = $event['type'] ?? '';

            // Usage from response.completed or response.done
            if (isset($event['response']['usage'])) {
                yield (new TokenUsageExtractor())->fromDataArray($event['response']);
            }

            // Text deltas: response.output_text.delta
            if (str_contains($type, 'output_text') && isset($event['delta'])) {
                yield new TextDelta($event['delta']);
            }

            // Thinking deltas: response.reasoning_summary_text.delta
            if ('response.reasoning_summary_text.delta' === $type && isset($event['delta'])) {
                if (null === $currentThinking) {
                    $currentThinking = '';
                    yield new ThinkingStart();
                }
                $currentThinking .= $event['delta'];
                yield new ThinkingDelta($event['delta']);
            }

            // Thinking complete: response.reasoning_summary_text.done
            if ('response.reasoning_summary_text.done' === $type) {
                yield new ThinkingComplete($currentThinking ?? '');
                $currentThinking = null;
            }

            // Tool calls: from response.completed output items
            if (str_contains($type, 'completed')) {
                $toolCalls = $this->extractToolCalls($event['response']['output'] ?? []);
                if ($toolCalls && 'response.completed' === $type) {
                    yield new ToolCallComplete($toolCalls);
                }
            }
        }
    }

    /**
     * @param array<array{type?: string, ...}> $output
     * @return ToolCall[]|null
     */
    private function extractToolCalls(array $output): ?array
    {
        $calls = [];
        foreach ($output as $item) {
            if ('function_call' === ($item['type'] ?? null)) {
                $calls[] = new ToolCall($item['call_id'], $item['name'], $item['arguments']);
            }
        }
        return $calls ?: null;
    }
}
```

**Stream event mapping (from pi-mono research)**:

| Codex SSE event type | Symfony AI delta |
|---------------------|-----------------|
| `response.output_text.delta` | `TextDelta` |
| `response.reasoning_summary_text.delta` | `ThinkingStart` (first) + `ThinkingDelta` |
| `response.reasoning_summary_text.done` | `ThinkingComplete` |
| `response.completed` with `function_call` output | `ToolCallComplete` |
| Usage in `response.completed` | `TokenUsage` |

Note: Codex reasoning is `reasoning_summary_text` (summarized thinking), not `reasoning.encrypted_content`. The `include: ["reasoning.encrypted_content"]` request parameter asks the server to include encrypted thinking for continuation, but the stream yields summary text. This matches the OpenResponses bridge behavior exactly.

#### 4. `CodexModelCatalog.php`

Static model catalog with known Codex models.

```php
namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;

class CodexModelCatalog implements ModelCatalogInterface
{
    // Model definitions from pi-mono research (see table above)
    // Each model → CodexModel with capabilities
}
```

#### 5. `Factory.php`

Creates `Provider` and `Platform` instances following the same pattern as `OpenResponses\Factory` and `Generic\Factory`.

```php
namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Factory
{
    public static function createProvider(
        string $baseUrl = 'https://chatgpt.com/backend-api',
        string $accessToken,
        string $accountId,
        ?HttpClientInterface $httpClient = null,
        ?ModelCatalogInterface $modelCatalog = null,
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $responsesPath = '/codex/responses',
        string $name = 'openai-codex',
    ): ProviderInterface {
        $httpClient = $httpClient instanceof EventSourceHttpClient
            ? $httpClient
            : new EventSourceHttpClient($httpClient);

        return new Provider(
            $name,
            [new CodexModelClient($httpClient, $baseUrl, $accessToken, $accountId, $responsesPath)],
            [new ResultConverter()],
            $modelCatalog ?? new CodexModelCatalog(),
            $contract,
            $eventDispatcher,
        );
    }

    public static function createPlatform(...): Platform
    {
        // Same pattern as OpenResponses\Factory::createPlatform
    }
}
```

#### 6. `TokenUsageExtractor.php`

Extracts token usage from Codex Responses API format. The usage structure differs from Completions API:

```
Completions:  { prompt_tokens, completion_tokens, cached_tokens }
Responses:    { input_tokens, output_tokens, input_token_details: { cached_tokens }, output_token_details: { reasoning_tokens } }
```

```php
namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\AI\Platform\Result\TokenUsage;
use Symfony\AI\Platform\Result\TokenUsageTrait;

class TokenUsageExtractor
{
    public function fromDataArray(array $data): TokenUsage
    {
        $usage = $data['usage'] ?? [];
        return new TokenUsage(
            inputTokens: $usage['input_tokens'] ?? 0,
            outputTokens: $usage['output_tokens'] ?? 0,
            cacheReadTokens: $usage['input_token_details']['cached_tokens'] ?? 0,
            cacheWriteTokens: 0,
            reasoningTokens: $usage['output_token_details']['reasoning_tokens'] ?? 0,
        );
    }
}
```

### Test Plan (following Symfony AI patterns)

#### `CodexModelClientTest.php`

Pattern: `MockHttpClient` with callback — assert HTTP method, URL, headers, body.

```php
class CodexModelClientTest extends TestCase
{
    public function testRequestWithCodexHeaders(): void
    {
        $callback = static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://chatgpt.com/backend-api/codex/responses', $url);
            self::assertSame('Authorization: Bearer test-access-token',
                $options['normalized_headers']['authorization'][0]);
            self::assertSame('chatgpt-account-id: acct-123',
                $options['normalized_headers']['chatgpt-account-id'][0]);
            self::assertSame('originator: hatfield',
                $options['normalized_headers']['originator'][0]);
            self::assertSame('OpenAI-Beta: responses=experimental',
                $options['normalized_headers']['openai-beta'][0]);
            self::assertStringContainsString('"model":"gpt-5.5"', $options['body']);
            return new MockResponse();
        };

        $client = new CodexModelClient(
            new MockHttpClient($callback),
            'https://chatgpt.com/backend-api',
            'test-access-token',
            'acct-123',
        );
        $client->request(new CodexModel('gpt-5.5'), ['input' => []], ['stream' => true]);
    }
}
```

#### `ResultConverterTest.php`

Pattern: `InMemoryRawResult` (from `symfony/ai-platform`) with inline event arrays for streaming, `createMock(ResponseInterface)` for static.

```php
class ResultConverterTest extends TestCase
{
    public function testStreamWithTextAndThinking(): void
    {
        $httpResponse = $this->createMock(ResponseInterface::class);
        $events = [
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'Thinking...'],
            ['type' => 'response.reasoning_summary_text.done', 'text' => 'Thinking...'],
            ['type' => 'response.output_text.delta', 'delta' => 'Hello'],
            ['type' => 'response.output_text.delta', 'delta' => ' world'],
            ['type' => 'response.completed', 'response' => [
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hello world']]]],
            ]],
        ];

        $raw = new InMemoryRawResult([], $events, $httpResponse);
        $converter = new ResultConverter();
        $result = $converter->convert($raw, ['stream' => true]);

        $deltas = iterator_to_array($result->asStream());
        // Assert ThinkingStart, ThinkingDelta, ThinkingComplete, TextDelta, TextDelta, TokenUsage
    }

    public function testStreamWithToolCall(): void
    {
        $events = [
            ['type' => 'response.output_text.delta', 'delta' => 'Let me'],
            ['type' => 'response.completed', 'response' => [
                'output' => [
                    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Let me help.']]],
                    ['type' => 'function_call', 'id' => 'call_1', 'name' => 'read_file', 'arguments' => '{"path":"/foo"}', 'call_id' => 'call_1'],
                ],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ]],
        ];
        // Assert ToolCallComplete with correct ToolCall
    }

    public function testAuthenticationError(): void
    {
        // 401 response → AuthenticationException
    }

    public function testRateLimitError(): void
    {
        // 429 response → RateLimitExceededException
    }
}
```

### Contract (Message Normalization)

For v1, the Codex bridge should reuse the Responses API message format. If the OpenResponses contract is available as a dependency, we can use `OpenResponsesContract::create()`. If not (to keep the bridge standalone), we implement our own normalizers following the same pattern:

Messages → Responses API `input[]` format:
```
SystemMessage     → { role: "system", content: "..." }  (but Codex uses `instructions` parameter, not system messages)
UserMessage       → { role: "user", content: "..." }
AssistantMessage  → { role: "assistant", content: "..." }  (includes tool calls as function_call_output)
ToolCall          → { type: "function_call", id, name, arguments, call_id }
ToolCallResult    → { type: "function_call_output", call_id, output }
```

The `instructions` field in the request body carries the system prompt. This is handled in the Hatfield integration layer (Task 2), not in the bridge itself — the bridge just passes through whatever the Contract normalizes.

---

## Task 2: Hatfield Integration

### New Files

```
src/CodingAgent/Auth/
├── OpenAICodexOAuthService.php      ← PKCE flow, token storage, refresh
├── OAuthCredentials.php             ← DTO: access_token, refresh_token, expires_at, account_id
└── OAuthCredentialsStorage.php      ← Read/write ~/.hatfield/auth.json

src/CodingAgent/Config/Ai/
└── (modify AiProviderConfig.php)    ← Add 'openai-codex-responses' api type support

src/CodingAgent/Infrastructure/SymfonyAi/
└── (modify SymfonyAiProviderFactory.php)  ← Build Codex provider via CodexModelClient
```

### OAuth PKCE Service

```php
namespace Ineers\CodingAgent\Auth;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAICodexOAuthService
{
    private const CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';
    private const AUTHORIZE_URL = 'https://auth.openai.com/oauth/authorize';
    private const TOKEN_URL = 'https://auth.openai.com/oauth/token';
    private const SCOPE = 'openid profile email offline_access';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OAuthCredentialsStorage $storage,
    ) {}

    /**
     * Initiate OAuth flow: start local server, open browser, wait for callback.
     */
    public function login(int $port = 1455): OAuthCredentials;

    /**
     * Refresh access token using refresh token.
     */
    public function refresh(OAuthCredentials $credentials): OAuthCredentials;

    /**
     * Get valid credentials, refreshing if expired.
     */
    public function getValidCredentials(): ?OAuthCredentials;

    /**
     * Extract chatgpt_account_id from JWT access token.
     */
    private function extractAccountId(string $accessToken): string;

    /**
     * Generate PKCE code_verifier and code_challenge (S256).
     */
    private function generatePKCE(): array;
}
```

**Login flow** (CLI-friendly):
1. Generate PKCE verifier + challenge
2. Start local HTTP server on port 1455 (or configured port)
3. Build authorization URL with PKCE params
4. Print URL to terminal + attempt `exec('open ...')` to open browser
5. Wait for callback on local server (race with manual code input)
6. Exchange authorization code for tokens
7. Extract account_id from JWT
8. Store credentials

**Token refresh**:
- Check `expires_at` before each request
- If expired, POST to token endpoint with `grant_type=refresh_token`
- If refresh fails (revoked), prompt for re-login

**Credentials storage**:
```json
// ~/.hatfield/auth.json
{
    "openai-codex": {
        "access_token": "...",
        "refresh_token": "...",
        "expires_at": 1718000000,
        "account_id": "acct-..."
    }
}
```

### Provider Config Integration

Add to `config/hatfield.defaults.yaml`:

```yaml
ai:
    providers:
        openai-codex:
            type: codex
            enabled: true
            # No base_url needed — hardcoded in bridge
            # No api_key — uses OAuth
            supports_thinking_levels: true
            compatibility:
                supports_developer_role: false  # Codex uses `instructions`, not `developer` role
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

### Factory Wiring

In `SymfonyAiProviderFactory::buildProvider()`, add a branch for `type: codex`:

```php
if ('codex' === $provider->type) {
    $credentials = $this->oauthService->getValidCredentials();
    if (null === $credentials) {
        throw new \RuntimeException('OpenAI Codex OAuth credentials not found. Run: hatfield auth codex');
    }
    return \Symfony\AI\Platform\Bridge\OpenAICodex\Factory::createProvider(
        accessToken: $credentials->accessToken,
        accountId: $credentials->accountId,
        httpClient: $this->httpClient,
        modelCatalog: $projectedCatalog,
        eventDispatcher: $this->eventDispatcher,
    );
}
```

### Reasoning Options

Add to `ReasoningOptionsResolver`:

```php
// Codex uses Responses API reasoning format: reasoning.effort
if ($this->isCodexResponsesApi($ref)) {
    return ['reasoning' => ['effort' => $mappedValue]];
}
```

The Responses API `reasoning` parameter format:
```json
{
    "reasoning": {
        "effort": "high",
        "summary": "auto"
    }
}
```

### CLI Auth Command

Add a Symfony console command:

```php
namespace Ineersa\CodingAgent\CLI;

class CodexAuthCommand extends Command
{
    protected static $defaultName = 'auth:codex';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $credentials = $this->oauthService->login();
        $output->writeln(sprintf('<info>Authenticated as account %s</info>', $credentials->accountId));
        return 0;
    }
}
```

### Deptrac Rules for Task 2

```
CodingAgent → OpenAICodexPlatform (allowed — Hatfield uses the bridge)
OpenAICodexPlatform → CodingAgent (forbidden — bridge knows nothing about Hatfield)
```

---

## Implementation Order

### Task 1 — Symfony AI OpenAICodex Bridge

1. Create directory structure under `src/Platform/Bridge/OpenAICodex/`
2. `CodexModel.php` — trivial model class
3. `CodexModelClient.php` — HTTP client with Codex headers
4. `TokenUsageExtractor.php` — Responses API usage parsing
5. `ResultConverter.php` — static + streaming result conversion
6. `CodexModelCatalog.php` — model definitions
7. `Factory.php` — provider/platform factory
8. `composer.json` — package definition (for future extraction)
9. Deptrac rule in `depfile.yaml`
10. Tests: `CodexModelClientTest.php`, `ResultConverterTest.php`
11. Validate: `castor deptrac` + `castor phpstan`

### Task 2 — Hatfield Integration (depends on Task 1)

1. `OAuthCredentials.php` — DTO
2. `OAuthCredentialsStorage.php` — JSON file read/write
3. `OpenAICodexOAuthService.php` — PKCE flow + refresh
4. `CodexAuthCommand.php` — CLI auth command
5. Register in `config/services.yaml`
6. Modify `AiProviderConfig.php` — handle `type: codex`
7. Modify `SymfonyAiProviderFactory.php` — build Codex provider
8. Add to `ReasoningOptionsResolver.php` — Responses API reasoning format
9. Add Codex provider to `config/hatfield.defaults.yaml`
10. Validate: `castor check`

---

## Future Considerations (out of scope)

- **WebSocket transport**: pi-mono uses WebSocket with session caching and delta continuation. SSE-only is fine for v1. WebSocket would reduce latency for multi-turn conversations.
- **Prompt caching**: `prompt_cache_key` parameter for Codex. Can be added as a request option later.
- **Service tier**: Flex/Priority pricing. The bridge should pass through the server-reported tier.
- **Upstream contribution**: Once stable, offer the bridge to the Symfony AI monorepo as `symfony/ai-openai-codex-platform`.
