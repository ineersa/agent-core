<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeCodec;
use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeFactory;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionSourceEventReference;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationAcceptedCompletionIdentity;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Psr\Container\ContainerInterface;

final class ToolCompletionReceiptFixture
{
    /** @return array{string, RunEvent} */
    public static function prepare(ContainerInterface $container, string $text = 'retained completion body', ?string $runId = null, string $callId = 'call-1'): array
    {
        $runId ??= $container->get(HatfieldSessionStore::class)->createSession('completion-delivery-source');
        $message = new ToolCallResult($runId, 1, 'step-1', 1, 'key-1', $callId, 0, ['content' => [['type' => 'text', 'text' => $text]]]);
        $payload = $container->get(ToolExecutionEndPayloadCodec::class)->toEventPayload($message);
        $completionId = ToolInvocationAcceptedCompletionIdentity::completionId($runId, 1, 'step-1', $callId);
        $envelope = $container->get(RetainedToolCompletionEnvelopeFactory::class)->build(
            completionId: $completionId,
            correlation: new DeferredToolCompletionCorrelation(
                deferredId: '550e8400-e29b-41d4-a716-446655440905',
                runId: $runId,
                turnNo: 1,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                toolCallId: $callId,
                toolName: 'bash',
                arguments: ['command' => 'true'],
                orderIndex: 0,
                invocationId: 'inv-1',
                executionOwnerKind: 'bash',
                deliveryFence: new \Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO(0, 0, 0),
            ),
            result: $message,
            normalizedToolResult: $payload['tool_result'],
            executionOwnerKind: 'bash',
        );
        $payload['accepted_completion'] = [
            'completion_id' => $completionId,
            'result_fingerprint' => ToolInvocationAcceptedCompletionIdentity::resultFingerprint($payload['tool_result']),
            'origin_run_id' => $runId,
            'origin_turn_no' => 1,
            'origin_step_id' => 'step-1',
            'tool_call_id' => $callId,
            'invocation_id' => 'inv-1',
            'detached' => true,
            'evidence_only' => false,
            'retained_message' => $container->get(RetainedToolCompletionEnvelopeCodec::class)->normalize($envelope),
        ];

        return [$runId, new RunEvent($runId, 0, 1, RunEventTypeEnum::ToolExecutionEnd->value, $payload)];
    }

    /** @return array{string, RunEvent, ToolCompletionDeliveryReferenceDTO} */
    public static function create(ContainerInterface $container, string $text = 'retained completion body', ?string $runId = null, string $callId = 'call-1'): array
    {
        [$runId, $event] = self::prepare($container, $text, $runId, $callId);
        $event = PreparedEventStoreSeeder::append($container->get(PreparedTransitionEventStoreInterface::class), $event);
        $envelope = $container->get(RetainedToolCompletionEnvelopeCodec::class)->denormalize($event->payload['accepted_completion']['retained_message']);

        return [$runId, $event, new ToolCompletionDeliveryReferenceDTO($runId, $envelope->messageId, new RetainedToolCompletionSourceEventReference($runId, $event->seq, $event->turnNo), $envelope->integrity->payloadFingerprint, $envelope->origin->deliveryFence)];
    }
}
