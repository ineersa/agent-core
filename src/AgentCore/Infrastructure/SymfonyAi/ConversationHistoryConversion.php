<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Request-time target-model conversion policy for AgentMessage lists.
 *
 * Canonical stored events are never rewritten. This helper remaps tool-call IDs,
 * converts thinking for foreign models, and sets preserve_native_item_ids metadata.
 * {@see AgentMessageConverter} owns the active MessageBag and when to apply this
 * policy.
 */
final class ConversationHistoryConversion
{
    public const string METADATA_PRESERVE_NATIVE_ITEM_IDS = 'preserve_native_item_ids';

    /**
     * Convert AgentMessages for a target model while updating call-id maps.
     *
     * @param list<AgentMessage>    $messages
     * @param array<string, string> $idMap
     * @param array<string, true>   $usedIds
     *
     * @return list<AgentMessage>
     */
    public function convertMessages(
        array $messages,
        string $targetModel,
        array &$idMap,
        array &$usedIds,
    ): array {
        $converted = [];
        foreach ($messages as $message) {
            $converted[] = $this->convertMessage($message, $targetModel, $idMap, $usedIds);
        }

        return $converted;
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
            $thinkingSignatures = \is_array($details['thinking_signatures'] ?? null)
                ? $details['thinking_signatures']
                : [];

            if (null !== $thinking || null !== $thinkingSignature || [] !== $thinkingSignatures) {
                if (\is_string($thinking) && '' !== $thinking) {
                    $content[] = [
                        'type' => 'text',
                        'text' => $thinking,
                    ];
                }

                unset($details['thinking'], $details['thinking_signature'], $details['thinking_signatures']);
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
