<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Replay;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

final readonly class ReplayAssistantMessageFactory
{
    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): ?AgentMessage
    {
        $msg = AgentMessage::fromPayload($payload);

        // fromPayload succeeded — standard path for text-bearing messages.
        if (null !== $msg) {
            return $this->withReplayedAssistantMetadata($msg, $payload);
        }

        // Only handle assistant-role payloads where content is null/missing.
        // fromPayload rejects these because is_array(content) fails, but
        // the real AgentMessageNormalizer produces this shape for
        // tool-call-only assistant responses.
        $role = $payload['role'] ?? null;

        if ('assistant' !== $role) {
            return null;
        }

        $metadata = $this->replayedAssistantMetadata($payload);
        $rawToolCalls = \is_array($metadata['tool_calls'] ?? null) ? $metadata['tool_calls'] : [];

        $details = \is_array($payload['details'] ?? null) && [] !== $payload['details']
            ? $payload['details']
            : null;

        // Filter thinking-only assistant messages (no content, no tool
        // calls, reasoning present in details). These were erroneously
        // persisted from provider reasoning-only responses (e.g. DeepSeek
        // when max_tokens is exhausted mid-thinking) and cannot be
        // replayed as valid conversation turns — providers reject
        // {content: null, reasoning_content: "..."}.
        if ([] === $rawToolCalls
            && null !== $details
            && \is_string($details['thinking'] ?? null)
        ) {
            return null;
        }

        return new AgentMessage(
            role: 'assistant',
            content: [],
            details: $details,
            metadata: $metadata,
        );
    }

    /**
     * Canonical llm_step_completed assistant payloads store tool_calls at the
     * top level (see AgentMessageNormalizer::assistantMessagePayload()).
     * AgentMessage::fromPayload() only reads metadata.*, so text-bearing
     * assistant messages must copy top-level tool_calls into metadata on replay.
     * Request-time conversion also needs the step model as source identity;
     * that comes from llm_step_completed.model, not a duplicated assistant field.
     *
     * @param array<string, mixed> $payload
     */
    private function withReplayedAssistantMetadata(AgentMessage $message, array $payload): AgentMessage
    {
        $replayed = $this->replayedAssistantMetadata($payload);
        if ([] === $replayed) {
            return $message;
        }

        $metadata = $message->metadata;
        foreach ($replayed as $key => $value) {
            $metadata[$key] = $value;
        }

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

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function replayedAssistantMetadata(array $payload): array
    {
        $metadata = [];

        $rawToolCalls = \is_array($payload['tool_calls'] ?? null) ? $payload['tool_calls'] : [];
        if ([] !== $rawToolCalls) {
            $metadata['tool_calls'] = $rawToolCalls;
        }

        // Derive request-local source identity from the step model. Do not
        // require a duplicated source_model field inside assistant_message.
        $sourceModel = \is_string($payload['model'] ?? null) ? $payload['model'] : null;
        if (\is_string($sourceModel) && '' !== $sourceModel) {
            $metadata['source_model'] = $sourceModel;
        }

        return $metadata;
    }
}
