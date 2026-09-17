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
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * Records a durable Astra reasoning transition against the newest request
 * segment after SessionAwareModelResolver decides an update is required.
 *
 * Prefers the MESSAGE_KEY identity stamped by
 * {@see AstraReasoningTransitionTransformHook} so OutputCap / conversion
 * text changes cannot desync the ledger from the MessageBag.
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
            if ($message instanceof AssistantMessage) {
                continue;
            }

            $existingKey = $message->getMetadata()->get(CodexReasoningTransitionMetadata::MESSAGE_KEY);
            if (\is_string($existingKey) && '' !== $existingKey) {
                return [$message, $existingKey];
            }

            if (!$message instanceof UserMessage && !$message instanceof ToolCallMessage) {
                continue;
            }

            // Synthetic image-only follow-ups and other non-text user messages
            // are not durable anchors; keep walking for a text/tool segment.
            if ($message instanceof UserMessage && ($message->hasImageContent() || !$this->hasTextContent($message))) {
                continue;
            }

            $messageKey = $this->fallbackMessageKey($messages, $i);
            if (null === $messageKey) {
                continue;
            }

            return [$message, $messageKey];
        }

        return null;
    }

    /**
     * @param list<MessageInterface> $messages
     */
    private function fallbackMessageKey(array $messages, int $index): ?string
    {
        $message = $messages[$index] ?? null;
        if (!$message instanceof UserMessage && !$message instanceof ToolCallMessage) {
            return null;
        }

        $role = $message instanceof ToolCallMessage ? 'tool' : 'user';
        $toolCallId = $message instanceof ToolCallMessage ? $message->getToolCall()->getId() : null;
        $text = $this->platformText($message);
        $occurrence = 0;
        for ($i = 0; $i < $index; ++$i) {
            $prior = $messages[$i];
            if (!$prior instanceof UserMessage && !$prior instanceof ToolCallMessage) {
                continue;
            }
            $priorRole = $prior instanceof ToolCallMessage ? 'tool' : 'user';
            $priorToolCallId = $prior instanceof ToolCallMessage ? $prior->getToolCall()->getId() : null;
            if ($priorRole !== $role || $priorToolCallId !== $toolCallId) {
                continue;
            }
            if ($this->platformText($prior) === $text) {
                ++$occurrence;
            }
        }

        return AstraReasoningTransitionTransformHook::messageKeyFor($role, $toolCallId, $text, $occurrence);
    }

    private function platformText(UserMessage|ToolCallMessage $message): string
    {
        if ($message instanceof ToolCallMessage) {
            return $message->asText() ?? '';
        }

        $parts = [];
        foreach ($message->getContent() as $part) {
            if ($part instanceof Text) {
                $text = $part->getText();
                if ('' !== $text) {
                    $parts[] = $text;
                }
            }
        }

        return implode("\n", $parts);
    }

    private function hasTextContent(UserMessage $message): bool
    {
        foreach ($message->getContent() as $part) {
            if ($part instanceof Text && '' !== $part->getText()) {
                return true;
            }
            // Image-only synthetic follow-ups are skipped as anchors.
            if ($part instanceof Image || $part instanceof ImageUrl) {
                continue;
            }
        }

        return false;
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
