<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeCodec;
use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeFactory;
use Ineersa\AgentCore\Contract\Tool\OwningRootSessionResolverInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedNativeHandoffBodyPromoterInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedOutputCapBodyPromoterInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Tests\Support\Fake\FakeRetainedToolCompletionBodyStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RetainedCompletionTestFactory
{
    public static function envelopeFactory(
        ?FakeRetainedToolCompletionBodyStore $bodies = null,
        ?OwningRootSessionResolverInterface $owningRoots = null,
        ?RetainedOutputCapBodyPromoterInterface $promoter = null,
        ?RetainedNativeHandoffBodyPromoterInterface $nativeHandoffPromoter = null,
        ?LoggerInterface $logger = null,
    ): RetainedToolCompletionEnvelopeFactory {
        $bodies ??= new FakeRetainedToolCompletionBodyStore();
        $codec = self::codec();

        return new RetainedToolCompletionEnvelopeFactory(
            $bodies,
            $codec,
            $owningRoots ?? new IdentityOwningRootSessionResolver(),
            $promoter,
            $nativeHandoffPromoter,
            $logger ?? new NullLogger(),
        );
    }

    public static function codec(): RetainedToolCompletionEnvelopeCodec
    {
        return new RetainedToolCompletionEnvelopeCodec(AttributeSerializerValidatorTestFactory::serializer(withBackedEnumNormalizer: true));
    }

    /**
     * Canonical ToolExecutionEnd tool_result MESSAGE for a ToolCallResult.
     *
     * @return array<string, mixed>
     */
    public static function messageToolResult(ToolCallResult $message): array
    {
        $payload = (new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec(
            AttributeSerializerValidatorTestFactory::serializer(withBackedEnumNormalizer: true),
        ))->toEventPayload($message);

        return $payload['tool_result'];
    }

    /**
     * @param array<string, mixed>|string|int|float|bool|null $result
     *
     * @return array{0: ToolCallResult, 1: array<string, mixed>}
     */
    public static function messageAndToolResult(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        string $toolCallId,
        int $orderIndex,
        array|string|int|float|bool|null $result,
        bool $isError = false,
        ?array $error = null,
    ): array {
        $message = new ToolCallResult(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: $attempt,
            idempotencyKey: $idempotencyKey,
            toolCallId: $toolCallId,
            orderIndex: $orderIndex,
            result: $result,
            isError: $isError,
            error: $error,
        );

        return [$message, self::messageToolResult($message)];
    }
}
