<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexReasoningTransitionMetadata;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexRequestBodyFactory;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * Records a durable Astra reasoning transition against the newest request
 * segment after SessionAwareModelResolver decides an update is required.
 *
 * Stamps MessageBag metadata so CodexMessageBagNormalizer can place
 * configuration_update immediately before that segment.
 */
final readonly class AstraReasoningTransitionRequestHook implements BeforeProviderRequestHookInterface
{
    public function __construct(
        private HatfieldSessionStore $sessionMetadataStore,
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
            return null;
        }

        $runId = $options['hatfield_run_id'] ?? null;
        $modelRef = $options['hatfield_model_ref'] ?? $model;
        if (!\is_string($runId) || '' === $runId || !\is_string($modelRef) || '' === $modelRef) {
            return null;
        }

        $anchor = $this->findNewestRequestAnchor($bag);
        if (null === $anchor) {
            return null;
        }

        [$message, $messageKey] = $anchor;
        $message->getMetadata()->add(CodexReasoningTransitionMetadata::KEY, $effort);
        $this->sessionMetadataStore->rememberReasoningTransition($runId, $modelRef, $messageKey, $effort);

        return null;
    }

    /**
     * @return array{0: MessageInterface, 1: string}|null
     */
    private function findNewestRequestAnchor(MessageBag $bag): ?array
    {
        $messages = $bag->withoutSystemMessage()->getMessages();
        $history = [];
        foreach ($messages as $message) {
            $agent = $this->toAgentMessage($message);
            if (null !== $agent) {
                $history[] = $agent;
            } else {
                $history[] = new AgentMessage(role: 'assistant', content: []);
            }
        }

        for ($i = \count($messages) - 1; $i >= 0; --$i) {
            $message = $messages[$i];
            if ($message instanceof AssistantMessage) {
                continue;
            }

            $agent = $this->toAgentMessage($message);
            if (null === $agent) {
                continue;
            }

            $messageKey = AstraReasoningTransitionTransformHook::messageKeyInHistory($history, $i);
            if (null === $messageKey) {
                continue;
            }

            return [$message, $messageKey];
        }

        return null;
    }

    private function toAgentMessage(MessageInterface $message): ?AgentMessage
    {
        if ($message instanceof UserMessage) {
            $text = '';
            foreach ($message->getContent() as $part) {
                if (method_exists($part, 'getText')) {
                    $partText = $part->getText();
                    if (\is_string($partText)) {
                        $text .= $partText;
                    }
                }
            }

            return new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => $text]]);
        }

        if ($message instanceof ToolCallMessage) {
            $toolCall = $message->getToolCall();
            $resultText = $message->asText() ?? '';

            return new AgentMessage(
                role: 'tool',
                content: [['type' => 'text', 'text' => $resultText]],
                toolCallId: $toolCall->getId(),
                toolName: $toolCall->getName(),
            );
        }

        return null;
    }
}
