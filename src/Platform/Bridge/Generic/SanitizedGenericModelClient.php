<?php

declare(strict_types=1);

namespace Ineersa\Platform\Bridge\Generic;

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
 */
final readonly class SanitizedGenericModelClient implements ModelClientInterface
{
    /**
     * @param list<string> $internalOptionKeys
     */
    public function __construct(
        private ModelClientInterface $inner,
        private array $internalOptionKeys = GenericProviderInternalOptionKeys::ALL,
    ) {
    }

    public function supports(Model $model): bool
    {
        return $this->inner->supports($model);
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        return $this->inner->request($model, $payload, $this->stripInternalKeys($options));
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
