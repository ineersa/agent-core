<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi;

use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\LlmHttpRetryPolicy;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http\LlmRetryingHttpClient;
use Ineersa\Platform\Bridge\Generic\DurableResultConverter;
use Ineersa\Platform\Bridge\Generic\SanitizedGenericModelClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Generic\Completions\ModelClient as GenericCompletionsModelClient;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\Embeddings\ModelClient as GenericEmbeddingsModelClient;
use Symfony\AI\Platform\Bridge\Generic\Embeddings\ResultConverter as GenericEmbeddingsResultConverter;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Creates Symfony AI Provider instances from Hatfield AI settings.
 *
 * Reads the merged Hatfield config from {@see AppConfig} (autowired DI
 * service built by {@see AppConfig::fromContainer}). For each enabled
 * provider in the Hatfield catalog it constructs a generic-chat-completions
 * Provider with a projected model catalog derived from Hatfield's rich
 * model definitions.
 *
 * This service is the bridge between Hatfield's user-facing model
 * config and Symfony AI Platform's provider model.
 */
class SymfonyAiProviderFactory
{
    /**
     * @param iterable<SymfonyAiProviderBuilderInterface> $builders
     */
    public function __construct(
        private readonly AppConfig $appConfig,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly iterable $builders = [],
        private readonly ?LoggerInterface $logger = null,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    /**
     * Create all enabled providers from the current Hatfield config.
     *
     * @return array<string, ProviderInterface> Providers keyed by Hatfield provider ID
     */
    public function createProviders(): array
    {
        $catalog = $this->appConfig->catalog;

        if (null === $catalog) {
            return [];
        }

        $providers = [];

        foreach ($catalog->config()->providers as $provider) {
            if (!$provider->enabled) {
                continue;
            }

            $providers[$provider->id] = $this->buildProvider($provider);
        }

        return $providers;
    }

    /**
     * Return a configured HttpClient for outgoing LLM requests.
     *
     * When an HttpClient is explicitly injected (e.g. test environment
     * via services_test.yaml, or by a test replay factory), use it
     * directly.  Otherwise create a default one wrapped with
     * {@see LlmRetryingHttpClient} for automatic retry/backoff.
     */
    private function getHttpClient(?string $providerId = null): HttpClientInterface
    {
        if (null !== $this->httpClient) {
            return $this->httpClient;
        }

        $http = $this->appConfig->ai?->http;
        $policy = new LlmHttpRetryPolicy(
            timeout: $http?->timeout,
            maxDuration: $http?->maxDuration,
            maxRetries: $http?->maxRetries,
            baseDelayMs: $http?->baseDelayMs,
            maxDelayMs: $http?->maxDelayMs,
        );
        $baseClient = HttpClient::create($policy->httpClientOptions());

        return new LlmRetryingHttpClient(
            httpClient: $baseClient,
            policy: $policy,
            logger: $this->logger ?? new NullLogger(),
            providerId: $providerId,
        );
    }

    /**
     * Build a single provider from Hatfield config.
     */
    private function buildProvider(AiProviderConfig $provider): ProviderInterface
    {
        $httpClient = $this->getHttpClient($provider->id);

        foreach ($this->builders as $builder) {
            if ($builder->supports($provider)) {
                return $builder->build($provider, $httpClient);
            }
        }

        return $this->buildGenericCompletionsProvider($provider, $httpClient);
    }

    /**
     * Build a generic chat-completions provider with durable streaming tool-call conversion.
     *
     * Replaces the vendor GenericFactory default ResultConverter with
     * {@see DurableResultConverter}, which uses dual-map (stream index +
     * tool-call id) tracking for robust sparse/out-of-order tool-call chunk
     * handling.  The HTTP client, embedding support, and Provider wiring
     * are identical to what GenericFactory creates.
     *
     * When HATFIELD_LLM_RAW_STREAM_CAPTURE=1 is set, the converter receives
     * a closure that writes raw chunks and converted deltas to a JSONL file.
     */
    private function buildGenericCompletionsProvider(AiProviderConfig $provider, HttpClientInterface $httpClient): ProviderInterface
    {
        $projectedCatalog = new ProjectedSymfonyModelCatalog(
            hatfieldModels: $provider->models,
            modelClass: CompletionsModel::class,
        );

        $httpClient = $httpClient instanceof EventSourceHttpClient ? $httpClient : new EventSourceHttpClient($httpClient);

        $modelClients = [];
        $resultConverters = [];

        if ($provider->supportsCompletions) {
            $completionsPath = $provider->completionsPath ?? '/v1/chat/completions';
            // Strip Hatfield-internal invocation metadata before the vendor client
            // merges options into the OpenAI-compatible wire JSON (cache keys, 400s).
            $modelClients[] = new SanitizedGenericModelClient(new GenericCompletionsModelClient(
                $httpClient,
                $provider->baseUrl,
                $this->resolveApiKey($provider->apiKey),
                $completionsPath,
            ));
            $resultConverters[] = new DurableResultConverter(
                onStreamEvent: $this->buildCaptureListener($provider->id),
            );
        }

        if ($provider->supportsEmbeddings) {
            $embeddingsPath = $provider->embeddingsPath ?? '/v1/embeddings';
            $modelClients[] = new SanitizedGenericModelClient(new GenericEmbeddingsModelClient(
                $httpClient,
                $provider->baseUrl,
                $this->resolveApiKey($provider->apiKey),
                $embeddingsPath,
            ));
            $resultConverters[] = new GenericEmbeddingsResultConverter();
        }

        return new Provider(
            $provider->id,
            $modelClients,
            $resultConverters,
            $projectedCatalog,
            null,  // contract — use Symfony AI default
            $this->eventDispatcher,
        );
    }

    /**
     * Build a stream event listener closure if capture is enabled.
     *
     * Checks HATFIELD_LLM_RAW_STREAM_CAPTURE (set to 1 to enable).
     * Optionally overrides the output path with HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH.
     * Default path: <cwd>/var/tmp/llm-raw-stream-capture-<timestamp>-<id>.jsonl
     *
     * @return (\Closure(string, int, array<string, mixed>): void)|null
     */
    private function buildCaptureListener(string $providerId): ?\Closure
    {
        $enabled = $_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE'] ?? getenv('HATFIELD_LLM_RAW_STREAM_CAPTURE');
        if (false === $enabled || '' === $enabled || '0' === $enabled || 'false' === $enabled) {
            return null;
        }

        // Resolve path
        $path = $_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH'] ?? getenv('HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH');
        if (false === $path || '' === $path) {
            $cwd = $_ENV['HATFIELD_CWD'] ?? getcwd();
            $ts = date('Ymd-His');
            $path = \sprintf('%s/var/tmp/llm-raw-stream-capture-%s-%s.jsonl', $cwd, $ts, bin2hex(random_bytes(4)));
        }

        // Harden permissions: dir 0700, file 0600 for sensitive debug artifacts.
        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create capture directory "%s".', $dir));
        }

        $handle = @fopen($path, 'ab');
        if (false === $handle) {
            throw new \RuntimeException(\sprintf('Cannot open capture file "%s" for writing.', $path));
        }
        @chmod($path, 0o600);

        $writeLine = static function (array $record) use ($handle): void {
            $line = json_encode($record, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
            if (false !== $line) {
                @fwrite($handle, $line."\n");
                @fflush($handle);
            }
        };

        // No capture_start written here — the converter emits capture_start
        // as the first event, enriched with provider_id by the closure below.

        return static function (string $event, int $ordinal, array $context) use ($writeLine, $providerId): void {
            $record = [
                'event' => $event,
                'ordinal' => $ordinal,
                'timestamp' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.uP'),
                'provider_id' => $providerId,
            ] + $context;

            $writeLine($record);
        };
    }

    /**
     * Resolve an API key value to its real string.
     *
     * Supports two formats:
     *  - Plain key: returned as-is.
     *  - env:VAR: resolved via getenv('VAR').
     *  - null: passed through.
     */
    private function resolveApiKey(?string $apiKey): ?string
    {
        if (null === $apiKey) {
            return null;
        }

        if (str_starts_with($apiKey, 'env:')) {
            $var = substr($apiKey, 4);
            $value = getenv($var);

            return false !== $value ? $value : null;
        }

        return $apiKey;
    }
}
