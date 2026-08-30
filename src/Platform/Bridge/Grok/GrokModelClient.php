<?php

declare(strict_types=1);

namespace Ineersa\Platform\Bridge\Grok;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\OpenResponses\ModelClient;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\JsonBodyEncodingTrait;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Open Responses ModelClient for xAI's cli-chat-proxy.grok.com.
 *
 * Extends the vendor OpenResponses ModelClient for stream-parser reuse, but
 * overrides request() because the parent hardcodes headers and merges options
 * into the JSON body (Hatfield-internal keys would 400). Spoofs the official
 * grok CLI client headers — the proxy returns HTTP 426 without them.
 *
 * @see https://cli-chat-proxy.grok.com/v1/responses
 * @see pi-grok-cli src/provider/stream.ts
 */
class GrokModelClient extends ModelClient
{
    use JsonBodyEncodingTrait;

    /**
     * Official grok CLI version spoofed in User-Agent / x-grok-client-version.
     *
     * The proxy version gate rejects requests without a grok client version
     * (HTTP 426). Bump this to match the current official grok CLI when
     * requests start failing (see pi-grok-cli src/provider/stream.ts).
     */
    public const string GROK_CLI_VERSION = '0.2.91';

    private readonly HttpClientInterface $httpClient;
    private readonly string $baseUrl;
    private readonly LoggerInterface $logger;

    /**
     * @param (\Closure(): ?string)|null $accessTokenRefresher
     */
    public function __construct(
        HttpClientInterface $httpClient,
        string $baseUrl,
        #[\SensitiveParameter] private readonly ?string $apiKey = null,
        private readonly string $path = '/v1/responses',
        ?LoggerInterface $logger = null,
        private readonly ?\Closure $accessTokenRefresher = null,
    ) {
        // Parent wraps its own EventSourceHttpClient for private fields.
        // Our request() path must also return AsyncResponse: vendor
        // RawSseStream does (new EventSourceHttpClient())->stream($response),
        // and AsyncDecoratorTrait only accepts AsyncResponse. A bare
        // CurlResponse/MockResponse TypeErrors. CodexSseStream is the only
        // parser that frames a bare client response itself.
        parent::__construct($httpClient, $baseUrl, $apiKey, $path);
        $this->httpClient = $httpClient instanceof EventSourceHttpClient
            ? $httpClient
            : new EventSourceHttpClient($httpClient);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(Model $model): bool
    {
        return $model instanceof ResponsesModel;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        if (\is_string($payload)) {
            throw new InvalidArgumentException(\sprintf('Payload must be an array, but a string was given to "%s".', self::class));
        }

        if (isset($options[PlatformSubscriber::RESPONSE_FORMAT]['json_schema']['schema'])) {
            $schema = $options[PlatformSubscriber::RESPONSE_FORMAT]['json_schema'];
            $options['text']['format'] = $schema;
            $options['text']['format']['name'] = $schema['name'];
            $options['text']['format']['type'] = $options[PlatformSubscriber::RESPONSE_FORMAT]['type'];

            unset($options[PlatformSubscriber::RESPONSE_FORMAT]);
        }

        $conversationId = $this->resolveConversationId($options, $payload);

        $body = array_merge($options, ['model' => $model->getName()], $payload);
        $body['prompt_cache_key'] = $conversationId;
        $body = $this->sanitizeWireBody($body);

        $requestOptions = [
            'headers' => $this->buildHeaders($model->getName(), $conversationId),
            'body' => $this->encodeJsonBody($body),
        ];

        if (null !== $this->apiKey) {
            $requestOptions['auth_bearer'] = $this->apiKey;
        }

        $url = $this->baseUrl.$this->path;
        $response = $this->httpClient->request('POST', $url, $requestOptions);

        if (401 === $response->getStatusCode() && null !== $this->accessTokenRefresher) {
            $retried = $this->refreshAndRetryOnce($requestOptions, $url, $response);
            if (null !== $retried) {
                $response = $retried;
            }
        }

        return new RawHttpResult($response, $this->createStreamParser());
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $payload
     */
    private function resolveConversationId(array $options, array $payload): string
    {
        $cacheKey = $payload['prompt_cache_key'] ?? $options['prompt_cache_key'] ?? null;
        if (\is_string($cacheKey) && '' !== $cacheKey) {
            return $cacheKey;
        }

        return UuidV7::v7()->toRfc4122();
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(string $modelId, string $conversationId): array
    {
        $version = self::GROK_CLI_VERSION;

        return [
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream',
            'User-Agent' => \sprintf('grok-pager/%s grok-shell/%s (macos; aarch64)', $version, $version),
            'x-grok-client-identifier' => 'grok-pager',
            'x-grok-client-version' => $version,
            'x-xai-token-auth' => 'xai-grok-cli',
            'x-grok-model-override' => $modelId,
            'x-grok-conv-id' => $conversationId,
        ];
    }

    /**
     * Drop Hatfield/xAI dialect footguns from the wire body.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function sanitizeWireBody(array $body): array
    {
        // Match pi-grok-cli + Codex: only request encrypted reasoning when the
        // caller asked for reasoning, and never overwrite a caller-supplied include.
        if (isset($body['reasoning'])) {
            $body['include'] ??= ['reasoning.encrypted_content'];
        }

        if (isset($body['input']) && \is_array($body['input'])) {
            $sanitized = [];
            foreach ($body['input'] as $item) {
                if (!\is_array($item)) {
                    $sanitized[] = $item;
                    continue;
                }

                if (isset($item['content']) && '' === $item['content']) {
                    continue;
                }

                if (($item['type'] ?? null) === 'reasoning') {
                    unset($item['status']);
                }

                $sanitized[] = $item;
            }
            $body['input'] = $sanitized;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $requestOptions
     */
    private function refreshAndRetryOnce(array $requestOptions, string $url, ResponseInterface $failedResponse): ?ResponseInterface
    {
        try {
            $fresh = ($this->accessTokenRefresher)();
        } catch (\Throwable $e) {
            $this->logger->warning('grok.token.refresh_failed', [
                'event_type' => 'grok.token.refresh_failed',
                'component' => 'grok_model_client',
                'attempt' => 1,
                'exception_class' => $e::class,
            ]);

            return null;
        }

        if (null === $fresh || $fresh === $this->apiKey) {
            return null;
        }

        $retryOptions = $requestOptions;
        $retryOptions['auth_bearer'] = $fresh;

        $this->logger->info('grok.token.refreshed_on_401', [
            'event_type' => 'grok.token.refreshed_on_401',
            'component' => 'grok_model_client',
            'attempt' => 1,
        ]);

        $failedResponse->cancel();

        return $this->httpClient->request('POST', $url, $retryOptions);
    }
}
