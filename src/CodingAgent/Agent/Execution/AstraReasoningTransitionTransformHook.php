<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\TransformContextHookInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexReasoningTransitionMetadata;

/**
 * Re-applies durable Astra reasoning-transition markers onto surviving history.
 *
 * New transitions are recorded by {@see AstraReasoningTransitionRequestHook}
 * after SessionAwareModelResolver decides an update is required. Compaction and
 * resume clear the session baseline so discarded switches are not kept.
 */
final readonly class AstraReasoningTransitionTransformHook implements TransformContextHookInterface
{
    public function __construct(
        private ModelSelectionService $selectionService,
        private HatfieldModelCatalog $catalog,
        private HatfieldSessionStore $sessionMetadataStore,
        private ?RunStartedMetadataReader $childMetadataReader = null,
    ) {
    }

    public function transformContext(array $messages, ?CancellationTokenInterface $cancelToken = null, ?string $runId = null): array
    {
        if (null === $runId || '' === $runId || [] === $messages) {
            return $messages;
        }

        $modelRef = $this->resolveModel($runId);
        if (null === $modelRef) {
            return $this->stripAllTransitions($messages);
        }

        $byKey = [];
        foreach ($this->sessionMetadataStore->listReasoningTransitions($runId, $modelRef->toString()) as $transition) {
            $byKey[$transition['message_key']] = $transition['effort'];
        }

        $out = [];
        foreach ($messages as $message) {
            $key = self::messageKeyInHistory($messages, \count($out));
            $effort = null !== $key ? ($byKey[$key] ?? null) : null;
            $metadata = $message->metadata;
            $current = $metadata[CodexReasoningTransitionMetadata::KEY] ?? null;

            if (\is_string($effort) && '' !== $effort) {
                if ($current === $effort) {
                    $out[] = $message;

                    continue;
                }

                $metadata[CodexReasoningTransitionMetadata::KEY] = $effort;
                $out[] = new AgentMessage(
                    role: $message->role,
                    content: $message->content,
                    timestamp: $message->timestamp,
                    name: $message->name,
                    toolCallId: $message->toolCallId,
                    toolName: $message->toolName,
                    details: $message->details,
                    isError: $message->isError,
                    metadata: $metadata,
                );

                continue;
            }

            if (\array_key_exists(CodexReasoningTransitionMetadata::KEY, $metadata)) {
                unset($metadata[CodexReasoningTransitionMetadata::KEY]);
                $out[] = new AgentMessage(
                    role: $message->role,
                    content: $message->content,
                    timestamp: $message->timestamp,
                    name: $message->name,
                    toolCallId: $message->toolCallId,
                    toolName: $message->toolName,
                    details: $message->details,
                    isError: $message->isError,
                    metadata: $metadata,
                );

                continue;
            }

            $out[] = $message;
        }

        return $out;
    }

    /**
     * @param non-empty-string $role
     */
    public static function messageKeyFor(string $role, ?string $toolCallId, string $text, int $occurrence): ?string
    {
        if (!\in_array($role, ['user', 'tool'], true)) {
            return null;
        }

        if ($occurrence < 0) {
            return null;
        }

        return hash('sha256', $role.'|'.($toolCallId ?? '').'|'.$text.'|'.$occurrence);
    }

    /**
     * Stable key for a message among siblings that share role/tool/text.
     *
     * @param list<AgentMessage> $messages
     */
    public static function messageKeyInHistory(array $messages, int $index): ?string
    {
        if (!isset($messages[$index])) {
            return null;
        }

        $message = $messages[$index];
        if (!\in_array($message->role, ['user', 'tool'], true)) {
            return null;
        }

        $text = self::textContent($message);
        $occurrence = 0;
        for ($i = 0; $i < $index; ++$i) {
            $prior = $messages[$i];
            if ($prior->role !== $message->role || ($prior->toolCallId ?? null) !== ($message->toolCallId ?? null)) {
                continue;
            }
            if (self::textContent($prior) === $text) {
                ++$occurrence;
            }
        }

        return self::messageKeyFor($message->role, $message->toolCallId, $text, $occurrence);
    }

    private static function textContent(AgentMessage $message): string
    {
        $text = '';
        foreach ($message->content as $part) {
            if (\is_array($part) && \is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        return $text;
    }

    /**
     * @param list<AgentMessage> $messages
     *
     * @return list<AgentMessage>
     */
    private function stripAllTransitions(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            if (!\array_key_exists(CodexReasoningTransitionMetadata::KEY, $message->metadata)) {
                $out[] = $message;

                continue;
            }

            $metadata = $message->metadata;
            unset($metadata[CodexReasoningTransitionMetadata::KEY]);
            $out[] = new AgentMessage(
                role: $message->role,
                content: $message->content,
                timestamp: $message->timestamp,
                name: $message->name,
                toolCallId: $message->toolCallId,
                toolName: $message->toolName,
                details: $message->details,
                isError: $message->isError,
                metadata: $metadata,
            );
        }

        return $out;
    }

    private function resolveModel(string $sessionId): ?AiModelReference
    {
        $explicitModel = null;
        if (!ctype_digit($sessionId) && null !== $this->childMetadataReader) {
            $childMetadata = $this->childMetadataReader->readRunStartedMetadata($sessionId);
            if (null !== $childMetadata && $childMetadata->isAgentChild()) {
                $explicitModel = $childMetadata->model;
            }
        }

        $modelRef = $this->selectionService->resolveInitialModel(
            explicitModel: $explicitModel,
            sessionId: $sessionId,
        );
        if (null === $modelRef) {
            return null;
        }

        if ('codex' !== $this->catalog->getProvider($modelRef->providerId)?->type
            || 'gpt-6-astra' !== $modelRef->modelName
            || true !== $this->catalog->getModel($modelRef)?->compatibility?->supportsReasoningConfigurationUpdates) {
            return null;
        }

        return $modelRef;
    }
}
