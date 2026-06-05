<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\AgentCore\Contract\Model\ModelResolverInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
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
            return new ResolvedModel(
                model: $modelRef->toString(),
                providerId: $modelRef->providerId,
                reasoning: $reasoning,
            );
        }

        throw new \RuntimeException('No AI model is configured. Add at least one enabled provider/model under ai.providers in ~/.hatfield/settings.yaml or project .hatfield/settings.yaml.');
    }
}
