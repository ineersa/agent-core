<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Request-time conversation history conversion for a target qualified model.
 *
 * Canonical stored events are never rewritten. This helper returns replacement
 * AgentMessage instances for the outgoing request only.
 */
final class ConversationHistoryConversion
{
    public const string METADATA_PRESERVE_NATIVE_ITEM_IDS = 'preserve_native_item_ids';

    /**
     * @param list<AgentMessage> $agentMessages
     *
     * @return list<AgentMessage>
     */
    public static function forTarget(array $agentMessages, string $targetModel): array
    {
        if ('' === $targetModel) {
            return $agentMessages;
        }

        $idMap = [];
        $converted = [];

        foreach ($agentMessages as $message) {
            if ('assistant' === $message->role) {
                $converted[] = self::convertAssistant($message, $targetModel, $idMap);
                continue;
            }

            if ('tool' === $message->role) {
                $converted[] = self::convertTool($message, $idMap);
                continue;
            }

            $converted[] = $message;
        }

        return $converted;
    }

    /**
     * Exact qualified identity only. Do not strip provider prefixes.
     */
    private static function isExactQualifiedMatch(?string $sourceModel, string $targetModel): bool
    {
        return \is_string($sourceModel) && '' !== $sourceModel && $sourceModel === $targetModel;
    }

    /**
     * Source identity comes from request-local metadata populated on replay
     * from llm_step_completed.model (not a duplicated stored assistant field).
     */
    private static function sourceModelFromMessage(AgentMessage $message): ?string
    {
        $sourceModel = $message->metadata['source_model'] ?? null;

        return \is_string($sourceModel) && '' !== $sourceModel ? $sourceModel : null;
    }

    /**
     * @param array<string, string> $idMap
     */
    private static function convertAssistant(AgentMessage $message, string $targetModel, array &$idMap): AgentMessage
    {
        $sourceModel = self::sourceModelFromMessage($message);
        $sameModel = self::isExactQualifiedMatch($sourceModel, $targetModel);
        $metadata = $message->metadata;
        $metadata[self::METADATA_PRESERVE_NATIVE_ITEM_IDS] = $sameModel;

        $details = \is_array($message->details) ? $message->details : null;
        $content = $message->content;

        if (!$sameModel && \is_array($details)) {
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

        $rawToolCalls = \is_array($metadata['tool_calls'] ?? null) ? $metadata['tool_calls'] : null;
        if (\is_array($rawToolCalls)) {
            $normalizedToolCalls = [];
            foreach ($rawToolCalls as $rawToolCall) {
                if (!\is_array($rawToolCall) || !\is_string($rawToolCall['id'] ?? null)) {
                    continue;
                }

                $originalId = $rawToolCall['id'];
                $requestId = self::normalizeToolCallId($originalId, $sameModel, $idMap);
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
    private static function convertTool(AgentMessage $message, array &$idMap): AgentMessage
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
     */
    private static function normalizeToolCallId(
        string $originalId,
        bool $sameModel,
        array &$idMap,
    ): string {
        if (isset($idMap[$originalId])) {
            return $idMap[$originalId];
        }

        if ($sameModel) {
            return $idMap[$originalId] = $originalId;
        }

        // Generic chat completions: bounded, collision-safe IDs.
        $candidate = self::sanitizeCompletionsToolCallId($originalId);
        $suffix = 0;
        while (\in_array($candidate, $idMap, true)) {
            $candidate = 'call_'.substr(hash('sha256', $originalId.'|'.++$suffix), 0, 35);
        }
        $idMap[$originalId] = $candidate;

        return $candidate;
    }

    private static function sanitizeCompletionsToolCallId(string $id): string
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
