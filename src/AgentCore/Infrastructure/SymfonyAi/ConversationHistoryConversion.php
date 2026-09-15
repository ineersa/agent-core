<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Request-time conversation history conversion for a target qualified model.
 *
 * Canonical stored events are never rewritten. This service returns a MessageBag
 * for the outgoing provider request only.
 *
 * Worker-local projection cache: one retained context per process. Exact source
 * context + target model reuse a shallow clone of the previous MessageBag
 * (bag structural copy). Message objects are treated as immutable after
 * creation; callers must not mutate returned message contents in place.
 * Pure appends convert only the suffix and append Symfony messages onto a
 * cloned bag. If the cached bag ended mid tool-result batch, only that trailing
 * incomplete batch is rebuilt so synthetic image ordering stays correct.
 * Model changes, compaction, history edits, and non-prefix contexts invalidate.
 *
 * Cached Image::fromFile closures reread bytes at serialization. When an
 * image_ref path becomes unreadable or readable again, the projection rebuilds
 * so placeholder degradation and restored attachments stay correct. No shared
 * database cache is used.
 *
 * Intentionally does not implement ResetInterface so Messenger service resets
 * leave the disposable projection intact across ExecuteLlmStep messages.
 */
final class ConversationHistoryConversion
{
    public const string METADATA_PRESERVE_NATIVE_ITEM_IDS = 'preserve_native_item_ids';

    /**
     * @var array{
     *     target_model: string,
     *     source_messages: list<AgentMessage>,
     *     converted_messages: list<AgentMessage>,
     *     message_bag: MessageBag,
     *     id_map: array<string, string>,
     *     used_ids: array<string, true>,
     *     incomplete_tool_batch_start: ?int,
     *     incomplete_tool_batch_stable_bag_count: ?int,
     *     image_readability: list<array<string, bool>>
     * }|null
     */
    private ?array $cache = null;

    public function __construct(
        private readonly AgentMessageConverter $messageConverter,
    ) {
    }

    /**
     * Convert request-time history for a target model, then build a MessageBag.
     *
     * @param list<AgentMessage> $agentMessages
     */
    public function toMessageBagForTarget(array $agentMessages, string $targetModel): MessageBag
    {
        if ('' === $targetModel) {
            return $this->messageConverter->toMessageBag($agentMessages);
        }

        if (null !== $this->cache
            && $this->cache['target_model'] === $targetModel
            && $this->isExactPrefix($this->cache['source_messages'], $agentMessages)
            && $this->imageReadabilityMatchesPrefix($agentMessages)
        ) {
            $cachedCount = \count($this->cache['source_messages']);
            if ($cachedCount === \count($agentMessages)) {
                return clone $this->cache['message_bag'];
            }

            return $this->appendProjection($agentMessages, $targetModel);
        }

        return $this->rebuildProjection($agentMessages, $targetModel);
    }

    /**
     * @param list<AgentMessage> $agentMessages
     */
    private function rebuildProjection(array $agentMessages, string $targetModel): MessageBag
    {
        $idMap = [];
        $usedIds = [];
        $converted = [];
        $imageReadability = [];

        foreach ($agentMessages as $message) {
            $converted[] = $this->convertMessage($message, $targetModel, $idMap, $usedIds);
            $imageReadability[] = $this->imageReadabilityForMessage($message);
        }

        $bag = $this->messageConverter->toMessageBag($converted);
        $incompleteStart = $this->trailingIncompleteToolBatchStart($converted);
        $stableBagCount = null;
        if (null !== $incompleteStart) {
            // Derive the stable bag boundary from the already-built bag and only
            // the open trailing tool batch. Never reconvert the stable prefix.
            $openBatch = \array_slice($converted, $incompleteStart);
            $stableBagCount = \count($bag->getMessages())
                - \count($this->messageConverter->convertAgentMessages($openBatch));
        }

        $this->cache = [
            'target_model' => $targetModel,
            'source_messages' => $agentMessages,
            'converted_messages' => $converted,
            'message_bag' => $bag,
            'id_map' => $idMap,
            'used_ids' => $usedIds,
            'incomplete_tool_batch_start' => $incompleteStart,
            'incomplete_tool_batch_stable_bag_count' => $stableBagCount,
            'image_readability' => $imageReadability,
        ];

        return clone $bag;
    }

    /**
     * @param list<AgentMessage> $agentMessages
     */
    private function appendProjection(array $agentMessages, string $targetModel): MessageBag
    {
        \assert(null !== $this->cache);

        $cachedCount = \count($this->cache['source_messages']);
        $idMap = $this->cache['id_map'];
        $usedIds = $this->cache['used_ids'];
        $converted = $this->cache['converted_messages'];
        $imageReadability = $this->cache['image_readability'];
        $suffixSource = \array_slice($agentMessages, $cachedCount);
        $suffixConverted = [];

        foreach ($suffixSource as $message) {
            $convertedMessage = $this->convertMessage($message, $targetModel, $idMap, $usedIds);
            $converted[] = $convertedMessage;
            $suffixConverted[] = $convertedMessage;
            $imageReadability[] = $this->imageReadabilityForMessage($message);
        }

        $bag = clone $this->cache['message_bag'];
        $rebuildFrom = $this->cache['incomplete_tool_batch_start'];
        if (null !== $rebuildFrom) {
            $stableCount = $this->cache['incomplete_tool_batch_stable_bag_count'] ?? 0;
            $bag = new MessageBag(...\array_slice($bag->getMessages(), 0, $stableCount));
            $rebuildConverted = array_merge(
                \array_slice($this->cache['converted_messages'], $rebuildFrom),
                $suffixConverted,
            );
            $this->appendConvertedMessages($bag, $rebuildConverted);
        } else {
            $this->appendConvertedMessages($bag, $suffixConverted);
        }

        $incompleteStart = $this->trailingIncompleteToolBatchStart($converted);
        $stableBagCount = null;
        if (null !== $incompleteStart) {
            // Derive the stable bag boundary from the already-built bag and only
            // the open trailing tool batch. Never reconvert the stable prefix.
            $openBatch = \array_slice($converted, $incompleteStart);
            $stableBagCount = \count($bag->getMessages())
                - \count($this->messageConverter->convertAgentMessages($openBatch));
        }

        $this->cache = [
            'target_model' => $targetModel,
            'source_messages' => $agentMessages,
            'converted_messages' => $converted,
            'message_bag' => $bag,
            'id_map' => $idMap,
            'used_ids' => $usedIds,
            'incomplete_tool_batch_start' => $incompleteStart,
            'incomplete_tool_batch_stable_bag_count' => $stableBagCount,
            'image_readability' => $imageReadability,
        ];

        return clone $bag;
    }

    /**
     * @param list<AgentMessage> $convertedMessages
     */
    private function appendConvertedMessages(MessageBag $bag, array $convertedMessages): void
    {
        foreach ($this->messageConverter->convertAgentMessages($convertedMessages) as $message) {
            $bag->add($message);
        }
    }

    /**
     * When the cached AgentMessage list ends inside a consecutive tool-result
     * batch, return the start index of that trailing incomplete batch so append
     * can rebuild only that region. Synthetic image UserMessages are deferred
     * until the tool batch closes, so an open trailing batch cannot keep its
     * Symfony messages as-is when later tool results arrive.
     *
     * @param list<AgentMessage> $convertedMessages
     */
    private function trailingIncompleteToolBatchStart(array $convertedMessages): ?int
    {
        $count = \count($convertedMessages);
        if (0 === $count || 'tool' !== $convertedMessages[$count - 1]->role) {
            return null;
        }

        $start = $count - 1;
        while ($start > 0 && 'tool' === $convertedMessages[$start - 1]->role) {
            --$start;
        }

        return $start;
    }

    /**
     * @param list<AgentMessage> $prefix
     * @param list<AgentMessage> $messages
     */
    private function isExactPrefix(array $prefix, array $messages): bool
    {
        $prefixCount = \count($prefix);
        if ($prefixCount > \count($messages)) {
            return false;
        }

        for ($i = 0; $i < $prefixCount; ++$i) {
            if (!$this->messagesEqual($prefix[$i], $messages[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<AgentMessage> $agentMessages
     */
    private function imageReadabilityMatchesPrefix(array $agentMessages): bool
    {
        \assert(null !== $this->cache);

        $cached = $this->cache['image_readability'];
        $prefixCount = \count($cached);
        for ($i = 0; $i < $prefixCount; ++$i) {
            if ($cached[$i] !== $this->imageReadabilityForMessage($agentMessages[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, bool>
     */
    private function imageReadabilityForMessage(AgentMessage $message): array
    {
        $readability = [];
        foreach ($message->content as $part) {
            if (!\is_array($part) || 'image_ref' !== ($part['type'] ?? null)) {
                continue;
            }

            $path = $part['path'] ?? null;
            if (!\is_string($path) || '' === $path) {
                continue;
            }

            $readability[$path] = is_file($path) && is_readable($path);
        }

        return $readability;
    }

    private function messagesEqual(AgentMessage $left, AgentMessage $right): bool
    {
        return $left->role === $right->role
            && $left->content === $right->content
            && $left->name === $right->name
            && $left->toolCallId === $right->toolCallId
            && $left->toolName === $right->toolName
            && $left->details === $right->details
            && $left->isError === $right->isError
            && $left->metadata === $right->metadata
            && $this->timestampsEqual($left->timestamp, $right->timestamp);
    }

    private function timestampsEqual(?\DateTimeImmutable $left, ?\DateTimeImmutable $right): bool
    {
        if ($left === $right) {
            return true;
        }

        if (null === $left || null === $right) {
            return false;
        }

        return $left->format(\DATE_ATOM) === $right->format(\DATE_ATOM);
    }

    /**
     * @param array<string, string> $idMap
     * @param array<string, true>   $usedIds
     */
    private function convertMessage(
        AgentMessage $message,
        string $targetModel,
        array &$idMap,
        array &$usedIds,
    ): AgentMessage {
        if ('assistant' === $message->role) {
            return $this->convertAssistant($message, $targetModel, $idMap, $usedIds);
        }

        if ('tool' === $message->role) {
            return $this->convertTool($message, $idMap);
        }

        return $message;
    }

    /**
     * Exact qualified identity only. Do not strip provider prefixes.
     */
    private function isExactQualifiedMatch(?string $sourceModel, string $targetModel): bool
    {
        return \is_string($sourceModel) && '' !== $sourceModel && $sourceModel === $targetModel;
    }

    /**
     * Source identity comes from request-local metadata populated on replay
     * from llm_step_completed.model (not a duplicated stored assistant field).
     */
    private function sourceModelFromMessage(AgentMessage $message): ?string
    {
        $sourceModel = $message->metadata['source_model'] ?? null;

        return \is_string($sourceModel) && '' !== $sourceModel ? $sourceModel : null;
    }

    /**
     * @param array<string, string> $idMap
     * @param array<string, true>   $usedIds
     */
    private function convertAssistant(
        AgentMessage $message,
        string $targetModel,
        array &$idMap,
        array &$usedIds,
    ): AgentMessage {
        $sourceModel = $this->sourceModelFromMessage($message);
        $sameModel = $this->isExactQualifiedMatch($sourceModel, $targetModel);
        $metadata = $message->metadata;
        $rawToolCalls = \is_array($metadata['tool_calls'] ?? null) ? $metadata['tool_calls'] : null;

        if ($sameModel) {
            if (\is_array($rawToolCalls)) {
                foreach ($rawToolCalls as $rawToolCall) {
                    if (!\is_array($rawToolCall) || !\is_string($rawToolCall['id'] ?? null)) {
                        continue;
                    }

                    $originalId = $rawToolCall['id'];
                    $idMap[$originalId] = $originalId;
                    $usedIds[$originalId] = true;
                }
            }

            if (true === ($metadata[self::METADATA_PRESERVE_NATIVE_ITEM_IDS] ?? null)) {
                return $message;
            }

            $metadata[self::METADATA_PRESERVE_NATIVE_ITEM_IDS] = true;

            return new AgentMessage(
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

        $metadata[self::METADATA_PRESERVE_NATIVE_ITEM_IDS] = false;
        $details = \is_array($message->details) ? $message->details : null;
        $content = $message->content;

        if (\is_array($details)) {
            $thinking = \is_string($details['thinking'] ?? null) ? $details['thinking'] : null;
            $thinkingSignature = \is_string($details['thinking_signature'] ?? null)
                ? $details['thinking_signature']
                : null;

            if (null !== $thinking || null !== $thinkingSignature) {
                if (\is_string($thinking) && '' !== $thinking) {
                    $content[] = [
                        'type' => 'text',
                        'text' => $thinking,
                    ];
                }

                unset($details['thinking'], $details['thinking_signature']);
                $details = [] !== $details ? $details : null;
            }
        }

        if (\is_array($rawToolCalls)) {
            $normalizedToolCalls = [];
            foreach ($rawToolCalls as $rawToolCall) {
                if (!\is_array($rawToolCall) || !\is_string($rawToolCall['id'] ?? null)) {
                    continue;
                }

                $originalId = $rawToolCall['id'];
                $requestId = $this->normalizeToolCallId($originalId, $idMap, $usedIds);
                $rawToolCall['id'] = $requestId;
                $normalizedToolCalls[] = $rawToolCall;
            }
            $metadata['tool_calls'] = $normalizedToolCalls;
        }

        return new AgentMessage(
            role: $message->role,
            content: $content,
            timestamp: $message->timestamp,
            name: $message->name,
            toolCallId: $message->toolCallId,
            toolName: $message->toolName,
            details: $details,
            isError: $message->isError,
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, string> $idMap
     */
    private function convertTool(AgentMessage $message, array &$idMap): AgentMessage
    {
        $toolCallId = $message->toolCallId;
        if (!\is_string($toolCallId) || '' === $toolCallId) {
            return $message;
        }

        // Tool results inherit remapping from earlier assistant tool calls.
        if (!isset($idMap[$toolCallId])) {
            return $message;
        }

        $requestId = $idMap[$toolCallId];
        if ($requestId === $toolCallId) {
            return $message;
        }

        return new AgentMessage(
            role: $message->role,
            content: $message->content,
            timestamp: $message->timestamp,
            name: $message->name,
            toolCallId: $requestId,
            toolName: $message->toolName,
            details: $message->details,
            isError: $message->isError,
            metadata: $message->metadata,
        );
    }

    /**
     * @param array<string, string> $idMap
     * @param array<string, true>   $usedIds
     */
    private function normalizeToolCallId(
        string $originalId,
        array &$idMap,
        array &$usedIds,
    ): string {
        if (isset($idMap[$originalId])) {
            return $idMap[$originalId];
        }

        // Generic chat completions: bounded, collision-safe IDs.
        $candidate = $this->sanitizeCompletionsToolCallId($originalId);
        $suffix = 0;
        while (isset($usedIds[$candidate])) {
            $candidate = 'call_'.substr(hash('sha256', $originalId.'|'.++$suffix), 0, 35);
        }
        $usedIds[$candidate] = true;
        $idMap[$originalId] = $candidate;

        return $candidate;
    }

    private function sanitizeCompletionsToolCallId(string $id): string
    {
        if (str_contains($id, '|')) {
            [$callId, $itemId] = explode('|', $id, 2);
            $callId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $callId) ?? $callId;
            $itemId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $itemId) ?? $itemId;
            $combined = '' !== $itemId ? $callId.'_'.$itemId : $callId;
            if (\strlen($combined) <= 40) {
                return $combined;
            }

            $hash = substr(hash('xxh128', $id), 0, 8);
            $prefix = substr($callId, 0, max(1, 40 - \strlen($hash) - 1));

            return $prefix.'_'.$hash;
        }

        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id) ?? $id;
        if (\strlen($sanitized) <= 40) {
            return $sanitized;
        }

        $hash = substr(hash('xxh128', $id), 0, 8);

        return substr($sanitized, 0, 40 - \strlen($hash) - 1).'_'.$hash;
    }
}
