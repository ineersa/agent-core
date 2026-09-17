<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexReasoningTransitionMetadata;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexRequestBodyFactory;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;

/**
 * Records a durable Astra reasoning transition against the newest request
 * segment after SessionAwareModelResolver decides an update is required.
 *
 * Anchors only on MESSAGE_KEY stamped by
 * {@see AstraReasoningTransitionTransformHook}. Synthetic converter follow-ups
 * (image placeholders, image-only users) have no key and are skipped so the
 * preceding keyed tool/user segment remains the durable anchor.
 */
final readonly class AstraReasoningTransitionRequestHook implements BeforeProviderRequestHookInterface
{
    public function __construct(
        private HatfieldSessionStore $sessionMetadataStore,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function beforeProviderRequest(
        string $model,
        array $input,
        array $options,
        ?CancellationTokenInterface $cancelToken = null,
    ): ?ProviderRequest {
        $effort = $options[CodexRequestBodyFactory::REASONING_UPDATE] ?? null;
        if (!\is_string($effort) || '' === $effort) {
            return null;
        }

        $bag = $input['message_bag'] ?? null;
        if (!$bag instanceof MessageBag) {
            return $this->degradeMissingAnchor($options, 'missing_message_bag');
        }

        $runId = $options['hatfield_run_id'] ?? null;
        $modelRef = $options['hatfield_model_ref'] ?? null;
        if (!\is_string($runId) || '' === $runId || !\is_string($modelRef) || '' === $modelRef) {
            return $this->degradeMissingAnchor($options, 'missing_run_or_model_ref');
        }

        $anchor = $this->findNewestRequestAnchor($bag);
        if (null === $anchor) {
            return $this->degradeMissingAnchor($options, 'missing_user_or_tool_anchor', $runId, $modelRef);
        }

        [$message, $messageKey] = $anchor;
        $message->getMetadata()->add(CodexReasoningTransitionMetadata::KEY, $effort);
        $message->getMetadata()->add(CodexReasoningTransitionMetadata::MESSAGE_KEY, $messageKey);
        $this->sessionMetadataStore->rememberReasoningTransition($runId, $modelRef, $messageKey, $effort);

        return null;
    }

    /**
     * @return array{0: MessageInterface, 1: string}|null
     */
    private function findNewestRequestAnchor(MessageBag $bag): ?array
    {
        $messages = $bag->withoutSystemMessage()->getMessages();
        for ($i = \count($messages) - 1; $i >= 0; --$i) {
            $message = $messages[$i];
            $existingKey = $message->getMetadata()->get(CodexReasoningTransitionMetadata::MESSAGE_KEY);
            if (!\is_string($existingKey) || '' === $existingKey) {
                continue;
            }

            return [$message, $existingKey];
        }

        return null;
    }

    /**
     * Drop the pending update option so factory cannot silently omit it while
     * keeping top-level baseline effort. Does not advance last_emitted.
     *
     * @param array<string, mixed> $options
     */
    private function degradeMissingAnchor(
        array $options,
        string $reason,
        ?string $runId = null,
        ?string $modelRef = null,
    ): ProviderRequest {
        $this->logger->warning('Astra reasoning transition could not find a durable request anchor; dropping configuration_update for this request.', [
            'component' => 'astra_reasoning_transition',
            'event_type' => 'astra.reasoning_transition.anchor_missing',
            'run_id' => $runId ?? ($options['hatfield_run_id'] ?? null),
            'session_id' => $runId ?? ($options['hatfield_run_id'] ?? null),
            'model' => $modelRef ?? ($options['hatfield_model_ref'] ?? null),
            'reason' => $reason,
        ]);

        $next = $options;
        unset(
            $next[CodexRequestBodyFactory::REASONING_UPDATE],
            $next['hatfield_run_id'],
            $next['hatfield_model_ref'],
        );

        return new ProviderRequest(options: $next);
    }
}
