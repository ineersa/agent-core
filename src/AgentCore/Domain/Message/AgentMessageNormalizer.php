<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Result\ToolCall;

final readonly class AgentMessageNormalizer
{
    /**
     * Content part type for image references that AgentMessageConverter
     * converts into real Symfony AI Image attachments.
     *
     * @see \Ineersa\CodingAgent\Tool\ViewImageTool::IMAGE_REF_TYPE
     */
    private const string IMAGE_REF_TYPE = 'image_ref';

    public function assistantMessage(AssistantMessage $assistantMessage): AgentMessage
    {
        $content = [];
        $text = $assistantMessage->asText();
        if (null !== $text) {
            $content[] = [
                'type' => 'text',
                'text' => $text,
            ];
        }

        $metadata = [];
        $toolCalls = $this->normalizeToolCalls($assistantMessage->getToolCalls());
        if ([] !== $toolCalls) {
            $metadata['tool_calls'] = $toolCalls;
        }

        $details = $this->extractThinkingDetails($assistantMessage);

        return new AgentMessage(
            role: 'assistant',
            content: $content,
            details: [] !== $details ? $details : null,
            metadata: $metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function assistantMessagePayload(AssistantMessage $assistantMessage): array
    {
        $text = $assistantMessage->asText();

        $payload = [
            'role' => 'assistant',
            'content' => null === $text
                ? null
                : [[
                    'type' => 'text',
                    'text' => $text,
                ]],
        ];

        $toolCalls = $this->normalizeToolCalls($assistantMessage->getToolCalls());
        if ([] !== $toolCalls) {
            $payload['tool_calls'] = $toolCalls;
        }

        $details = $this->extractThinkingDetails($assistantMessage);

        if ([] !== $details) {
            $payload['details'] = $details;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function humanResponseMessage(array $payload): ?AgentMessage
    {
        if (!\array_key_exists('answer', $payload)) {
            return null;
        }

        $content = json_encode([
            'question_id' => \is_string($payload['question_id'] ?? null) ? $payload['question_id'] : null,
            'answer' => $payload['answer'],
        ]);

        if (false === $content) {
            $content = '{}';
        }

        return new AgentMessage(
            role: 'user',
            content: [[
                'type' => 'text',
                'text' => $content,
            ]],
            metadata: \is_string($payload['question_id'] ?? null)
                ? ['question_id' => $payload['question_id']]
                : [],
        );
    }

    public function toolMessage(ToolCallResult $result): AgentMessage
    {
        $text = json_encode([
            'is_error' => $result->isError,
            'result' => $result->result,
            'error' => $result->error,
        ]);

        if (false === $text) {
            $text = '{}';
        }

        // Build content parts: start with the standard text part
        $content = [[
            'type' => 'text',
            'text' => $text,
        ]];

        // Check if the tool result contains view_image metadata.
        // If so, add an image_ref content part that AgentMessageConverter
        // will use to attach a real Symfony AI Image to the next request.
        // The raw_result['type'] === 'view_image' and the raw_result has
        // path/media_type/bytes/width/height from ViewImageTool.
        $rawResult = $result->result['details']['raw_result'] ?? null;
        if (\is_array($rawResult) && 'view_image' === ($rawResult['type'] ?? null)) {
            $path = $rawResult['path'] ?? null;
            $mediaType = $rawResult['media_type'] ?? null;
            $bytes = $rawResult['bytes'] ?? null;
            $width = $rawResult['width'] ?? null;
            $height = $rawResult['height'] ?? null;

            if (\is_string($path) && '' !== $path) {
                $imageRef = [
                    'type' => self::IMAGE_REF_TYPE,
                    'path' => $path,
                ];

                if (\is_string($mediaType)) {
                    $imageRef['media_type'] = $mediaType;
                }
                if (null !== $bytes) {
                    $imageRef['bytes'] = $bytes;
                }
                if (null !== $width) {
                    $imageRef['width'] = $width;
                }
                if (null !== $height) {
                    $imageRef['height'] = $height;
                }

                $content[] = $imageRef;
            }
        }

        $toolName = \is_string($result->result['tool_name'] ?? null) ? $result->result['tool_name'] : null;

        return new AgentMessage(
            role: 'tool',
            content: $content,
            toolCallId: $result->toolCallId,
            toolName: $toolName,
            details: $result->result,
            isError: $result->isError,
            metadata: [
                'order_index' => $result->orderIndex,
            ],
        );
    }

    /**
     * Extracts thinking details from the 0.9 content-based AssistantMessage.
     *
     * @return array{thinking?: string|null, thinking_signature?: string|null}
     */
    private function extractThinkingDetails(AssistantMessage $assistantMessage): array
    {
        if (!$assistantMessage->hasThinking()) {
            return [];
        }

        $thinkingParts = $assistantMessage->getThinking();

        // Concatenate all thinking content blocks.
        $thinkingContent = implode('', array_map(
            static fn (Thinking $t): string => $t->getContent(),
            $thinkingParts,
        ));

        // Signatures are per-part; we keep the last non-null signature (there is typically at most one).
        $thinkingSignature = null;
        foreach ($thinkingParts as $part) {
            if (null !== $part->getSignature()) {
                $thinkingSignature = $part->getSignature();
            }
        }

        return array_filter([
            'thinking' => '' !== $thinkingContent ? $thinkingContent : null,
            'thinking_signature' => $thinkingSignature,
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @param ?list<ToolCall> $toolCalls
     *
     * @return list<array{id: string, name: string, arguments: array<string, mixed>, order_index: int}>
     */
    private function normalizeToolCalls(?array $toolCalls): array
    {
        if (null === $toolCalls) {
            return [];
        }

        $normalized = [];

        foreach ($toolCalls as $index => $toolCall) {
            if (!$toolCall instanceof ToolCall) {
                continue;
            }

            $normalized[] = [
                'id' => $toolCall->getId(),
                'name' => $toolCall->getName(),
                'arguments' => $toolCall->getArguments(),
                'order_index' => $index,
            ];
        }

        return $normalized;
    }
}
