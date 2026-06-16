<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\AgentCore\Contract\Model\ModelResolverInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Production model resolver backed by Hatfield model/reasoning selection services.
 *
 * Uses {@see ModelSelectionService} to resolve the per-turn model and reasoning
 * level following the documented priority (explicit → session → default → first
 * available). Parses the resolved model reference to extract the provider ID so
 * that {@see ModelResolverRoutingSubscriber} can set an explicit provider on the
 * {@see ModelRoutingEvent}.
 *
 * Also resolves a simple compat-features list and pre-computed reasoning options
 * from the Hatfield model catalog, so AgentCore's compat shapers receive everything
 * they need without depending on CodingAgent internals.
 */
final class SessionAwareModelResolver implements ModelResolverInterface
{
    public function __construct(
        private readonly ModelSelectionService $selectionService,
        private readonly HatfieldModelCatalog $catalog,
    ) {
    }

    public function resolve(
        string $defaultModel,
        MessageBag $messages,
        ModelInvocationInput $input,
        ModelResolutionOptions $options,
    ): ResolvedModel {
        unset($defaultModel, $messages, $options);

        $sessionId = $input->runId ?? '';

        // Do NOT pass $defaultModel as $explicitModel — the event's defaultModel is the
        // legacy container parameter (now ''), not a user override. The user's explicit
        // choice flows through StartRunRequest → RunMetadata → session metadata and is
        // picked up by the 2nd priority tier (session metadata).
        $modelRef = $this->selectionService->resolveInitialModel(
            explicitModel: null,
            sessionId: $sessionId,
        );

        $reasoning = $this->selectionService->resolveInitialReasoning(
            explicitReasoning: null,
            sessionId: $sessionId,
        );

        if (null !== $modelRef) {
            // Clamp the reasoning level to the model's supported levels.
            // A persisted xhigh for a model that only supports up to high
            // must be resolved to high so enable_thinking is honoured.
            $reasoning = $this->selectionService->clampReasoningLevel($reasoning, $modelRef);

            $compatFeatures = $this->resolveCompatFeatures($modelRef);
            $reasoningOptions = $this->resolveReasoningOptions($modelRef, $reasoning);

            // Always pass 'reasoning' in compat features when reasoning is active.
            if ([] !== $reasoningOptions && !\in_array('reasoning', $compatFeatures, true)) {
                $compatFeatures[] = 'reasoning';
            }

            return new ResolvedModel(
                model: $modelRef->toString(),
                providerId: $modelRef->providerId,
                reasoning: $reasoning,
                compatFeatures: $compatFeatures,
                reasoningOptions: $reasoningOptions,
            );
        }

        throw new \RuntimeException('No AI model is configured. Add at least one enabled provider/model under ai.providers in ~/.hatfield/settings.yaml or project .hatfield/settings.yaml.');
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
            $features[] = 'zai_tool_stream';
        }

        if ($compat->requiresReasoningContentOnAssistantMessages) {
            $features[] = 'requires_reasoning_content_on_assistant';
        }

        return $features;
    }

    /**
     * Pre-compute provider-specific reasoning options for the given model
     * and reasoning level.
     *
     * Uses {@see ReasoningOptionsResolver} to produce options such as
     * {@code enable_thinking}, {@code reasoning_effort}, {@code thinking.type}.
     * This is done in CodingAgent where the catalog is available; AgentCore's
     * {@see \Ineersa\AgentCore\Infrastructure\SymfonyAi\ReasoningOptionsFeatureShaper}
     * only merges the result.
     *
     * @return array<string, mixed>
     */
    private function resolveReasoningOptions(AiModelReference $ref, string $reasoningLevel): array
    {
        if ('' === $reasoningLevel || 'off' === $reasoningLevel) {
            return [];
        }

        $resolver = new ReasoningOptionsResolver($this->catalog);

        return $resolver->resolve($ref, $reasoningLevel);
    }
}
