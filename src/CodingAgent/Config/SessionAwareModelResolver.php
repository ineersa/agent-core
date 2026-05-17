<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\AgentCore\Contract\Tool\ModelResolverInterface;
use Ineersa\AgentCore\Domain\Tool\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Tool\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Tool\ResolvedModel;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Production model resolver backed by Hatfield model/reasoning selection services.
 *
 * Uses {@see ModelSelectionService} to resolve the per-turn model and reasoning
 * level following the documented priority (explicit → session → default → first
 * available). Parses the resolved model reference to extract the provider ID so
 * that {@see ModelResolverRoutingSubscriber} can set an explicit provider on the
 * {@see ModelRoutingEvent}.
 */
final class SessionAwareModelResolver implements ModelResolverInterface
{
    public function __construct(
        private readonly ModelSelectionService $selectionService,
    ) {
    }

    public function resolve(
        string $defaultModel,
        MessageBag $messages,
        ModelInvocationInput $input,
        ModelResolutionOptions $options,
    ): ResolvedModel {
        unset($messages, $options);

        $sessionId = $input->runId ?? '';

        // Do NOT pass $defaultModel as $explicitModel — the event's defaultModel is the
        // hardcoded container parameter, not a user override. The user's explicit choice
        // flows through StartRunRequest → RunMetadata → session metadata and is picked
        // up by the 2nd priority tier (session metadata).
        $modelRef = $this->selectionService->resolveInitialModel(
            explicitModel: null,
            sessionId: $sessionId,
        );

        $reasoning = $this->selectionService->resolveInitialReasoning(
            explicitReasoning: null,
            sessionId: $sessionId,
        );

        if (null !== $modelRef) {
            return new ResolvedModel(
                model: $modelRef->toString(),
                providerId: $modelRef->providerId,
                reasoning: $reasoning,
            );
        }

        // Fall back to the default model string when no models configured
        $parsed = AiModelReference::tryParse($defaultModel);

        return new ResolvedModel(
            model: $defaultModel,
            providerId: null !== $parsed ? $parsed->providerId : '',
            reasoning: $reasoning,
        );
    }
}
