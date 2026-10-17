<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationAcceptedCompletionIdentity;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\InMemoryDeferredToolCompletionRepository;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class CompleteDeferredToolCallHandlerTest extends TestCase
{
    public function testMatchingCompletedRedeliveryIsNoOp(): void
    {
        [$handler, $deferred, $bus, $complete] = $this->acceptedHandler();
        $handler($complete);
        $this->assertCount(0, $bus->messages);
        $this->assertSame('completed', $deferred->status($complete->deferredId));
    }

    public function testConflictingCompletedRedeliveryFailsClosed(): void
    {
        [$handler, $deferred, $bus, $complete] = $this->acceptedHandler();
        $conflict = new CompleteDeferredToolCall(
            deferredId: $complete->deferredId,
            content: [['type' => 'text', 'text' => 'different terminal']],
            details: null,
            isError: false,
            error: null,
        );

        try {
            $handler($conflict);
            $this->fail('Conflicting completed redelivery must fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Conflicting native deferred completion', $exception->getMessage());
        }

        $this->assertCount(0, $bus->messages);
        $this->assertSame('completed', $deferred->status($complete->deferredId));
    }

    public function testPendingDeliveryPublishesWithoutMarkingCompleted(): void
    {
        $deferred = new InMemoryDeferredToolCompletionRepository();
        $correlation = $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440701',
            runId: 'run-complete-pending',
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'complete-pending-key',
            toolCallId: 'call-complete-pending',
            toolName: 'fork',
            arguments: ['task' => 'x'],
            orderIndex: 0,
        ));
        $bus = new TestMessageBus();
        $handler = new CompleteDeferredToolCallHandler(
            $deferred,
            $bus,
            new RunLockManager(new LockFactory(new InMemoryStore())),
            new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()),
            new TestLogger(),
        );
        $handler(new CompleteDeferredToolCall(
            deferredId: $correlation->deferredId,
            content: [['type' => 'text', 'text' => 'pending body']],
        ));
        $this->assertSame('pending', $deferred->status($correlation->deferredId));
        $this->assertCount(1, $bus->messages);
        $this->assertInstanceOf(ToolCallResult::class, $bus->messages[0]);
    }

    /**
     * @return array{0: CompleteDeferredToolCallHandler, 1: InMemoryDeferredToolCompletionRepository, 2: TestMessageBus, 3: CompleteDeferredToolCall}
     */
    private function acceptedHandler(): array
    {
        $deferred = new InMemoryDeferredToolCompletionRepository();
        $correlation = $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440702',
            runId: 'run-complete-accepted',
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'complete-accepted-key',
            toolCallId: 'call-complete-accepted',
            toolName: 'fork',
            arguments: ['task' => 'x'],
            orderIndex: 0,
        ));
        $codec = new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer());
        $pending = new CompleteDeferredToolCall(
            deferredId: $correlation->deferredId,
            content: [['type' => 'text', 'text' => 'accepted body']],
        );
        $published = ToolCallResultFactory::fromDeferredCorrelationAndCompletion(
            $correlation,
            $pending->content,
            $pending->details,
            $pending->isError,
            $pending->error,
        );
        $published = $codec->finalizeResult($published);
        $fingerprint = ToolInvocationAcceptedCompletionIdentity::resultFingerprint(
            $codec->toEventPayload($published)['tool_result'],
        );
        $deferred->applyLifecycleProjection(new RecordToolInvocationLifecycleDTO(
            $correlation,
            ToolInvocationCallerDispositionEnum::ForegroundResultAccepted,
            ToolInvocationExecutionObservationEnum::Terminal,
            ToolInvocationAcceptedCompletionIdentity::completionIdFromCorrelation($correlation),
            $fingerprint,
        ));
        $bus = new TestMessageBus();
        $handler = new CompleteDeferredToolCallHandler(
            $deferred,
            $bus,
            new RunLockManager(new LockFactory(new InMemoryStore())),
            $codec,
            new TestLogger(),
        );

        return [$handler, $deferred, $bus, $pending];
    }
}
