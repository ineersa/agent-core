<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationCodec;
use Ineersa\AgentCore\Domain\Run\RunState;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

final readonly class ToolMessageProjector
{
    public function __construct(
        private AgentMessageNormalizer $messageNormalizer,
        private DenormalizerInterface $serializer,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function buildToolCallInfoMap(RunState $state): array
    {
        $toolCallInfoMap = [];
        foreach (array_reverse($state->messages) as $replayMsg) {
            if ('assistant' === $replayMsg->role && \is_array($replayMsg->metadata['tool_calls'] ?? null)) {
                foreach ($replayMsg->metadata['tool_calls'] as $tc) {
                    if (\is_string($tc['id'] ?? null)) {
                        $toolCallInfoMap[$tc['id']] = $tc;
                    }
                }
                break;
            }
        }

        return $toolCallInfoMap;
    }

    /** @param list<AgentMessage> $messages */
    public function stateContainsToolMessageForId(array $messages, string $toolCallId): bool
    {
        foreach ($messages as $message) {
            if ('tool' === $message->role && $message->toolCallId === $toolCallId) {
                return true;
            }
        }

        return false;
    }

    /** @param list<AgentMessage> $messages */
    public function appendToolMessage(array &$messages, ToolCallResult $result): void
    {
        $notifications = ModelNotificationCodec::denormalizeFromDetails($this->serializer, $result->result['details'] ?? null);
        $messages[] = $this->messageNormalizer->toolMessage($result, $notifications);
    }
}
