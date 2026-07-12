<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\Uid\UuidV4;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class CodexModelClient implements ModelClientInterface
{
    /**
     * Internal option keys that must not be serialized into the Codex JSON body.
     *
     * These keys are injected by Hatfield/Symfony AI infrastructure for routing,
     * metadata, or hook dispatch and are not valid Codex Responses API fields.
     *
     * Note: 'stream' is NOT in this list because Codex requires it (stream=true).
     * The LlmPlatformAdapter injects stream=true, and we preserve it through.
     */
    private const array INTERNAL_OPTION_KEYS = [
        '_agent_core_invocation',
        '_hatfield_reasoning',
        'tool_stream',
        'tools_ref',
        'turn_no',
        'run_id',
    ];

    private readonly HttpClientInterface $httpClient;
    private readonly LoggerInterface $logger;

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $accessToken,
        private readonly string $accountId,
        private readonly string $path = '/codex/responses',
        private readonly string $originator = 'hatfield',
        ?LoggerInterface $logger = null,
        /** @var (\Closure(): ?string)|null */
        private readonly ?\Closure $accessTokenRefresher = null,
    ) {
        $this->httpClient = $httpClient;
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(Model $model): bool
    {
        return $model instanceof CodexModel;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        if (\is_string($payload)) {
            throw new InvalidArgumentException(\sprintf('Payload must be an array, but a string was given to "%s".', self::class));
        }

        // Transform structured output options before option sanitization.
        if (isset($options[PlatformSubscriber::RESPONSE_FORMAT]['json_schema']['schema'])) {
            $schema = $options[PlatformSubscriber::RESPONSE_FORMAT]['json_schema'];
            $options['text']['format'] = $schema;
            $options['text']['format']['name'] = $schema['name'];
            $options['text']['format']['type'] = $options[PlatformSubscriber::RESPONSE_FORMAT]['type'];

            unset($options[PlatformSubscriber::RESPONSE_FORMAT]);
        }

        // Strip Hatfield/Symfony AI internal keys that are not valid Codex API fields.
        $bodyOptions = array_diff_key($options, array_flip(self::INTERNAL_OPTION_KEYS));

        // Prompt caching: use run_id (session ID) as the cache key.
        // run_id is stripped from bodyOptions above, so we extract it
        // before merging. Pi-mono: prompt_cache_key: sessionId.
        $runId = $options['run_id'] ?? null;

        // Merge payload (from contract) over options, with model name last
        // so CodexContract's payload keys (input, instructions) always win
        // over any top-level option keys.
        $jsonBody = array_merge($bodyOptions, ['model' => $model->getName()], $payload);

        // Prompt cache key from session/run ID (added after merge so an
        // explicit caller value in payload takes precedence via ??=).
        if (\is_string($runId) && '' !== $runId) {
            $jsonBody['prompt_cache_key'] ??= $runId;
        }

        // Apply Codex Responses API defaults for required fields that are
        // not explicitly set by the caller or the contract. These match the
        // pi-mono openai-codex-responses.ts buildRequestBody shape.
        $jsonBody['store'] ??= false;
        $jsonBody['stream'] ??= true;

        // text may already have 'format' from structured output; merge verbosity in.
        if (!isset($jsonBody['text'])) {
            $jsonBody['text'] = ['verbosity' => 'low'];
        } elseif (!isset($jsonBody['text']['verbosity'])) {
            $jsonBody['text']['verbosity'] = 'low';
        }

        $jsonBody['include'] ??= ['reasoning.encrypted_content'];
        $jsonBody['tool_choice'] ??= 'auto';
        $jsonBody['parallel_tool_calls'] ??= true;

        $requestOptions = [
            'auth_bearer' => $this->accessToken,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
                'User-Agent' => 'hatfield',
                'chatgpt-account-id' => $this->accountId,
                'originator' => $this->originator,
                'OpenAI-Beta' => 'responses=experimental',
                'x-client-request-id' => UuidV4::v4()->toRfc4122(),
            ],
            'json' => $jsonBody,
        ];

        $this->logRequestSummary($model, $jsonBody);

        // Use a bare Http client (no EventSourceHttpClient wrapping) because the
        // Codex backend may not return text/event-stream Content-Type. Our
        // CodexSseStream (passed via RawHttpResult) handles SSE parsing
        // independently of the content-type header.
        $response = $this->httpClient->request('POST', $this->baseUrl.$this->path, $requestOptions);

        if (401 === $response->getStatusCode() && null !== $this->accessTokenRefresher) {
            $retried = $this->refreshAndRetryOnce($requestOptions, $model, $response);
            if (null !== $retried) {
                $response = $retried;
            }
        }

        return new RawHttpResult($response, new CodexSseStream());
    }

    /**
     * On 401, force-refresh OAuth once and retry the POST with the new bearer token.
     *
     * Bounded: at most one refresh and one retry. On retry, the failed 401 response
     * is cancelled before the retry POST. Returns null when refresh is unavailable,
     * unchanged, or throws — caller keeps the original 401 response (uncancelled).
     *
     * @param array<string, mixed> $requestOptions
     */
    private function refreshAndRetryOnce(array $requestOptions, Model $model, ResponseInterface $failedResponse): ?ResponseInterface
    {
        try {
            $fresh = ($this->accessTokenRefresher)();
        } catch (\Throwable $e) {
            $this->logger->warning('codex.token.refresh_failed', [
                'event_type' => 'codex.token.refresh_failed',
                'component' => 'codex_model_client',
                'model' => $model->getName(),
                'attempt' => 1,
                'exception_class' => $e::class,
            ]);

            return null;
        }

        if (null === $fresh || $fresh === $this->accessToken) {
            return null;
        }

        $retryOptions = $requestOptions;
        $retryOptions['auth_bearer'] = $fresh;
        $retryOptions['headers']['x-client-request-id'] = UuidV4::v4()->toRfc4122();

        $this->logger->info('codex.token.refreshed_on_401', [
            'event_type' => 'codex.token.refreshed_on_401',
            'component' => 'codex_model_client',
            'model' => $model->getName(),
            'attempt' => 1,
        ]);

        // Release the failed 401 connection before the retry POST
        // (mirrors LlmRetryingHttpClient's cancel-before-retry).
        $failedResponse->cancel();

        return $this->httpClient->request('POST', $this->baseUrl.$this->path, $retryOptions);
    }

    /**
     * Log a privacy-safe summary of the outgoing Codex request.
     *
     * Only structural metadata (key names, counts, booleans) is logged.
     * Prompt text, tool content, access tokens, and account IDs are never
     * included.
     *
     * @param array<string, mixed> $jsonBody
     */
    private function logRequestSummary(Model $model, array $jsonBody): void
    {
        $input = $jsonBody['input'] ?? [];
        $inputCount = \is_array($input) ? \count($input) : 0;
        $tools = $jsonBody['tools'] ?? [];

        // Summarize input content types without revealing actual text.
        $inputTypes = [];
        if (\is_array($input)) {
            foreach ($input as $item) {
                if (isset($item['type']) && \is_string($item['type'])) {
                    $inputTypes[$item['type']] = true;
                }
                if (isset($item['role']) && \is_string($item['role'])) {
                    $inputTypes['role:'.$item['role']] = true;
                }
            }
        }

        $this->logger->info('llm.provider.request_prepared', [
            'event_type' => 'llm.provider.request_prepared',
            'request_url_path' => $this->path,
            'model' => $model->getName(),
            'body_keys' => implode(', ', array_keys($jsonBody)),
            'input_count' => $inputCount,
            'input_types' => [] !== $inputTypes ? implode(', ', array_keys($inputTypes)) : 'none',
            'tool_count' => \is_array($tools) ? \count($tools) : 0,
            'has_instructions' => isset($jsonBody['instructions']),
            'has_reasoning' => isset($jsonBody['reasoning']),
            'has_include' => isset($jsonBody['include']),
            'has_text' => isset($jsonBody['text']),
            'has_store' => isset($jsonBody['store']),
            'has_stream' => isset($jsonBody['stream']),
            'has_client_request_id' => true,
            'originator' => $this->originator,
        ]);
    }
}
