<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolResultText;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Owns the canonical typed ToolExecutionEnd payload shape.
 *
 * ToolExecutionEnd producers and consumers exchange a ToolCallResult through
 * this codec rather than knowing the serializer context or nested wire shape.
 */
final readonly class ToolExecutionEndPayloadCodec
{
    private const string TOOL_RESULT_KEY = 'tool_result';

    public function __construct(
        private NormalizerInterface&DenormalizerInterface $serializer,
    ) {
    }

    /** Check the serializer representation before accepting a terminal result. */
    public function finalizeResult(ToolCallResult $result): ToolCallResult
    {
        return $this->inspectResult($result)[0];
    }

    /** @return array<string, mixed> */
    public function normalizeResult(ToolCallResult $result): array
    {
        [$result, $normalized] = $this->inspectResult($result);

        // A valid representation is already concrete data. Only a replacement
        // failure result needs normalization again, through the same codec.
        return $normalized ?? $this->normalizeArray($result);
    }

    /** @return array{tool_result: array<string, mixed>} */
    public function toEventPayload(ToolCallResult $result): array
    {
        $result = $result->finalized();
        $normalized = $this->normalizeArray($result);
        if (!ToolResultText::isValid($normalized)) {
            // Never persist a different representation after owner admission.
            throw new \UnexpectedValueException('ToolExecutionEnd normalization violated the terminal data contract.');
        }

        return [self::TOOL_RESULT_KEY => $normalized];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function fromEventPayload(array $payload): ToolCallResult
    {
        $normalized = $payload[self::TOOL_RESULT_KEY] ?? null;
        if (!\is_array($normalized)) {
            throw new \UnexpectedValueException('ToolExecutionEnd payload must contain an array tool_result.');
        }

        try {
            $result = $this->serializer->denormalize($normalized, ToolCallResult::class);
        } catch (\Throwable $exception) {
            throw new \UnexpectedValueException('ToolExecutionEnd tool_result is malformed.', previous: $exception);
        }

        if (!$result instanceof ToolCallResult) {
            throw new \UnexpectedValueException('ToolExecutionEnd tool_result must denormalize to ToolCallResult.');
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function normalizeArray(ToolCallResult $result): array
    {
        $normalized = $this->serializer->normalize($result);
        if (!\is_array($normalized)) {
            throw new \UnexpectedValueException('ToolExecutionEnd tool_result normalization must produce an array.');
        }

        return $normalized;
    }

    /** @return array{ToolCallResult, array<string, mixed>|null} */
    private function inspectResult(ToolCallResult $result): array
    {
        // Admission checks the actual representation before batch storage or
        // model projection, not just the object supplied to the serializer.
        $result = $result->finalized();
        try {
            $normalized = $this->serializer->normalize($result);
        } catch (\Throwable $exception) {
            $failure = $exception instanceof \JsonException && \JSON_ERROR_UTF8 === $exception->getCode()
                ? ToolResultText::FAILURE_MESSAGE
                : ToolResultText::INSPECTION_FAILURE_MESSAGE;

            return [$result->withRepresentationFailure($failure), null];
        }
        $failure = \is_array($normalized) ? ToolResultText::failureMessage($normalized) : ToolResultText::INSPECTION_FAILURE_MESSAGE;

        return null === $failure ? [$result, $normalized] : [$result->withRepresentationFailure($failure), null];
    }
}
