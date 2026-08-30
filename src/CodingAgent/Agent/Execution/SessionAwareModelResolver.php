<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Model\ModelResolverInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ReasoningContentFeatureShaper;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ReasoningOptionsFeatureShaper;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ZaiToolStreamFeatureShaper;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\ReasoningOptionsResolver;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Production model resolver backed by Hatfield model/reasoning selection services.
 *
 * Uses {@see ModelSelectionService} to resolve the per-turn model and reasoning
 * level following the documented priority (explicit → session → default → first
 * available). The resolved provider-qualified model lets Symfony AI route through
 * provider-aware projected catalogs without separate control metadata.
 *
 * It also resolves compatibility data and the small set of provider-facing
 * options that Hatfield intentionally maps for the selected provider.
 */
final class SessionAwareModelResolver implements ModelResolverInterface
{
    public function __construct(
        private readonly ModelSelectionService $selectionService,
        private readonly HatfieldModelCatalog $catalog,
        private readonly HatfieldSessionStore $sessionMetadataStore,
        private readonly ?SubagentRunMetadataReader $childMetadataReader = null,
    ) {
    }

    public function resolve(
        string $defaultModel,
        MessageBag $messages,
        ModelInvocationInput $input,
        ModelResolutionOptions $options,
    ): ResolvedModel {
        unset($messages);

        $sessionId = $input->runId ?? '';

        // Non-empty $defaultModel is an explicit override (e.g. compaction
        // model, background summarization, extension agent runs). Empty string
        // means no override — resolve from session metadata / defaults.
        $explicitModel = '' !== $defaultModel ? $defaultModel : null;

        // Read thinking_level from ModelResolutionOptions when non-empty.
        // This allows compaction (and future summarization callers) to pass
        // an explicit reasoning/thinking level override that flows through
        // the resolver, overriding session metadata and defaults.
        // Absent, null, or empty string => no override.
        $explicitReasoning = \is_string($options->values['thinking_level'] ?? null) && '' !== $options->values['thinking_level']
            ? $options->values['thinking_level']
            : null;

        // Agent child runs (fork/subagent) keep their RunStarted definition
        // model/reasoning; ordinary sessions (numeric ids) resolve mutable
        // session metadata so a picked model wins over historical run_started.
        if (null === $explicitModel && null !== $this->childMetadataReader && !ctype_digit($sessionId)) {
            $childMetadata = $this->childMetadataReader->readRunStartedMetadata($sessionId);
            if (null !== $childMetadata && $childMetadata->isAgentChild()) {
                $explicitModel = $childMetadata->model;
                if (null === $explicitReasoning && null !== $childMetadata->reasoning) {
                    $explicitReasoning = $childMetadata->reasoning;
                }
            }
        }

        $modelRef = $this->selectionService->resolveInitialModel(
            explicitModel: $explicitModel,
            sessionId: $sessionId,
        );

        $reasoning = $this->selectionService->resolveInitialReasoning(
            explicitReasoning: $explicitReasoning,
            sessionId: $sessionId,
        );

        if (null !== $modelRef) {
            // Clamp the reasoning level to the model's supported levels.
            // A persisted xhigh for a model that only supports up to high
            // must be resolved to high so z.ai thinking options are honoured.
            $reasoning = $this->selectionService->clampReasoningLevel($reasoning, $modelRef);

            $compatFeatures = $this->resolveCompatFeatures($modelRef);
            $reasoningOptions = $this->resolveReasoningOptions($modelRef, $reasoning);

            // Pass 'reasoning' compat when options are present (z.ai off sends disabled thinking).
            if ([] !== $reasoningOptions && !\in_array(ReasoningOptionsFeatureShaper::FEATURE, $compatFeatures, true)) {
                $compatFeatures[] = ReasoningOptionsFeatureShaper::FEATURE;
            }

            return new ResolvedModel(
                model: $modelRef->toString(),
                providerId: $modelRef->providerId,
                reasoning: $reasoning,
                providerOptions: $this->resolveProviderOptions($modelRef, $sessionId),
                compatFeatures: $compatFeatures,
                reasoningOptions: $reasoningOptions,
            );
        }

        throw new \RuntimeException('No AI model is configured. Add at least one enabled provider/model under ai.providers in ~/.hatfield/settings.yaml or project .hatfield/settings.yaml.');
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveProviderOptions(AiModelReference $modelRef, string $sessionId): array
    {
        $provider = $this->catalog->getProvider($modelRef->providerId);
        if (null === $provider) {
            throw new \RuntimeException(\sprintf('Provider "%s" is not configured.', $modelRef->providerId));
        }

        $session = '' !== $sessionId ? $this->sessionMetadataStore->findSession($sessionId) : null;
        if (null === $session && ctype_digit($sessionId)) {
            throw new \RuntimeException(\sprintf('Session "%s" has no metadata for model resolution.', $sessionId));
        }

        if ('grok' === $provider->type && '' !== $sessionId) {
            return ['prompt_cache_key' => $sessionId];
        }

        if ('codex' !== $provider->type || null === $session) {
            return [];
        }

        $providerCacheKey = $session->providerCacheKey;
        if (null === $providerCacheKey || '' === $providerCacheKey) {
            throw new \RuntimeException(\sprintf('Session "%s" is missing a provider_cache_key.', $sessionId));
        }

        if (!Uuid::isValid($providerCacheKey) || !Uuid::fromString($providerCacheKey) instanceof UuidV7) {
            throw new \RuntimeException(\sprintf('Session "%s" has an invalid provider_cache_key.', $sessionId));
        }

        return ['prompt_cache_key' => $providerCacheKey];
    }

    /**
     * Resolve a simple list of compat features for the given model reference.
     *
     * Reads model-level AND provider-level compatibility metadata and
     * translates it into a plain string array. No DTOs, no resolvers —
     * just a list of flags.
     *
     * @return list<string>
     */
    private function resolveCompatFeatures(AiModelReference $ref): array
    {
        $model = $this->catalog->getModel($ref);
        $compat = (null !== $model ? $model->compatibility : null)
            ?? $this->catalog->getProvider($ref->providerId)?->compatibility;

        if (null === $compat) {
            return [];
        }

        $features = [];

        if ($compat->zaiToolStream) {
            $features[] = ZaiToolStreamFeatureShaper::FEATURE;
        }

        if ($compat->requiresReasoningContentOnAssistantMessages) {
            $features[] = ReasoningContentFeatureShaper::FEATURE;
        }

        return $features;
    }

    /**
     * Pre-compute provider-specific reasoning options for the given model
     * and reasoning level.
     *
     * Uses {@see ReasoningOptionsResolver} to produce options such as
     * {@code thinking.type}, {@code reasoning_effort}, {@code reasoning.effort}.
     * This is done in CodingAgent where the catalog is available; AgentCore's
     * {@see ReasoningOptionsFeatureShaper}
     * only merges the result.
     *
     * @return array<string, mixed>
     */
    private function resolveReasoningOptions(AiModelReference $ref, string $reasoningLevel): array
    {
        if ('' === $reasoningLevel) {
            return [];
        }

        $resolver = new ReasoningOptionsResolver($this->catalog);

        return $resolver->resolve($ref, $reasoningLevel);
    }
}
