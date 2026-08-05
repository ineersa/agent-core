<?php

declare(strict_types=1);

namespace Ineersa\Platform\Bridge\Generic;

use Ineersa\Platform\Diagnostics\PromptCacheRequestDiagnosticsRecorder;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawResultInterface;

/**
 * Strips Hatfield-internal option keys before delegating to a generic Symfony AI
 * ModelClient.
 *
 * Used only for generic completions/embeddings providers built by
 * SymfonyAiProviderFactory. Codex and other custom model clients are not wrapped
 * so they can map internal correlation context (e.g. run_id to prompt_cache_key)
 * in provider-specific code.
 *
 * Shared pre-inner seam also records privacy-safe request diagnostics when a
 * recorder is present in options (transport label: http).
 */
final readonly class SanitizedGenericModelClient implements ModelClientInterface
{
    /**
     * @param list<string> $internalOptionKeys
     */
    public function __construct(
        private ModelClientInterface $inner,
        private array $internalOptionKeys = GenericProviderInternalOptionKeys::ALL,
        private string $provider = 'unknown',
    ) {
    }

    public function supports(Model $model): bool
    {
        return $this->inner->supports($model);
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        $this->recordDiagnostics($model, $payload, $options);

        return $this->inner->request($model, $payload, $this->stripInternalKeys($options));
    }

    /**
     * @param array<string, mixed>|string $payload
     * @param array<string, mixed>        $options
     */
    private function recordDiagnostics(Model $model, array|string $payload, array $options): void
    {
        $recorder = $options[PromptCacheRequestDiagnosticsRecorder::OPTION_KEY] ?? null;
        if (!$recorder instanceof PromptCacheRequestDiagnosticsRecorder) {
            return;
        }

        $logicalBody = \is_array($payload) ? $payload : ['content' => $payload];
        // Preserve exact/near-final messages/tools while attaching remaining non-internal options.
        foreach ($options as $key => $value) {
            if (PromptCacheRequestDiagnosticsRecorder::OPTION_KEY === $key || \in_array($key, $this->internalOptionKeys, true)) {
                continue;
            }
            if (!\array_key_exists($key, $logicalBody)) {
                $logicalBody[$key] = $value;
            }
        }

        $hmacKeySource = '';
        if (\is_string($options['provider_cache_key'] ?? null) && '' !== $options['provider_cache_key']) {
            $hmacKeySource = $options['provider_cache_key'];
        } elseif (\is_string($options['run_id'] ?? null) && '' !== $options['run_id']) {
            $hmacKeySource = $options['run_id'];
        }

        $recorder->record(
            logicalBody: $logicalBody,
            provider: $this->provider,
            transport: 'http',
            hmacKeySource: $hmacKeySource,
            wireMeta: [
                'mode' => 'full_context',
                'model' => $model->getName(),
                'prompt_cache_key_present' => \is_string($options['provider_cache_key'] ?? null) && '' !== $options['provider_cache_key'],
                'previous_response_id_present' => false,
            ],
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function stripInternalKeys(array $options): array
    {
        foreach ($this->internalOptionKeys as $key) {
            unset($options[$key]);
        }

        return $options;
    }
}
