<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Messenger\ExecutionAuthorizationMiddleware;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class ExecutionAuthorizationMiddlewareTest extends IsolatedKernelTestCase
{
    public static function kinds(): iterable
    {
        yield 'LLM' => ['llm'];
        yield 'compaction' => ['compaction'];
        yield 'shell' => ['shell'];
        yield 'tool' => ['tool'];
    }

    #[DataProvider('kinds')]
    public function testReferenceTransportIsSmallAndNotificationFailureReusesDurableResult(string $kind): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('immutable execution delivery');
        $text = 'private invocation text '.str_repeat('valid input ', 10000);
        $message = new AgentMessage('user', [['type' => 'text', 'text' => $text]]);
        $request = match ($kind) {
            'llm' => new ExecuteLlmStep($run, 1, 'invoke', 1, 'original', 'tools', [$message]),
            'compaction' => new ExecuteCompactionStep($run, 1, 'invoke', 1, 'original', 'test/model', [], [$message], [], 1, 0, 1, 1000, 'manual'),
            'shell' => new ExecuteShellToolCall($run, 1, 'invoke', 1, 'shell-call', $text, true),
            'tool' => new ExecuteToolCall($run, 1, 'invoke', 1, 'original', 'tool-call', 'echo', ['command' => $text], 0),
        };
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $operations = $container->get(ExecutionOperationStoreInterface::class);
        $authorization = $operations->arm($request, $pending);
        $reference = $operations->requestReference($request, $authorization);
        $store->finalizeVerifiedTransition($run, $pending->identity);
        $envelope = new Envelope($reference, [$authorization]);
        $container->get('agent.execution.bus')->dispatch($envelope);
        $transportName = match ($kind) {
            'shell', 'tool' => 'tool',
            default => 'llm',
        };
        $transport = $container->get('messenger.transport.'.$transportName);
        $queued = iterator_to_array($transport->get());
        $this->assertCount(1, $queued);
        $this->assertEquals($reference, $queued[0]->getMessage());
        $serializer = $container->get('messenger.transport.native_php_serializer');
        $encoded = $serializer->encode($queued[0]);
        $this->assertLessThan(3000, \strlen($encoded['body']));
        $this->assertStringNotContainsString('private invocation text', $encoded['body']);
        $received = $serializer->decode($encoded)->with(new ReceivedStamp($transportName));
        $invoker = $this->createMock(MiddlewareInterface::class);
        $commandBus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $durable = null;
        $expectedResult = null;
        $commandBus->expects($this->once())->method('dispatch')->willReturnCallback(function (object $message) use (&$durable): Envelope {
            $durable = $message;
            $this->assertInstanceOf(DurableExecutionResult::class, $durable);
            throw new \RuntimeException('notification unavailable');
        });
        $invoker->expects($this->once())->method('handle')->willReturnCallback(function (Envelope $invocation) use ($request, $kind, &$expectedResult): Envelope {
            $this->assertEquals($request, $invocation->getMessage(), 'The winning worker resolves the exact frozen input.');
            $identity = [$request->runId(), $request->turnNo(), $request->stepId(), $request->attempt(), $request->idempotencyKey()];
            $result = match ($kind) {
                'llm' => new LlmStepResult(...[...$identity, null, [], 'stop']),
                'compaction' => new CompactionStepResult(...[...$identity, 'original summary', null, [], 1, 0, 1, 1000, 'manual']),
                'shell' => new ToolCallResult(...[...$identity, 'shell-call', 0, ['content' => [['type' => 'text', 'text' => 'original output']]]]),
                'tool' => new ToolCallResult(...[...$identity, 'tool-call', 0, ['content' => [['type' => 'text', 'text' => 'original output']]]]),
            };
            $expectedResult = $result;

            return $invocation->with(new HandledStamp($result, 'specialized-worker'));
        });
        $gate = new ExecutionAuthorizationMiddleware(
            $operations,
            $commandBus,
            $container->get(DeferredToolCompletionRepositoryInterface::class),
            [
                ExecuteLlmStep::class => 'llm',
                ExecuteCompactionStep::class => 'llm',
                ExecuteShellToolCall::class => 'tool',
                ExecuteToolCall::class => 'tool',
            ],
        );
        try {
            $gate->handle($received, new StackMiddleware($invoker));
            $this->fail('Notification failure must prevent successful handling.');
        } catch (HandlerFailedException $exception) {
            $this->assertSame('notification unavailable', $exception->getWrappedExceptions()[0]->getMessage());
            $this->assertEquals($reference, $exception->getEnvelope()->getMessage(), 'Broker retry must retain the reference, not the decoded invocation.');
        }
        $this->assertInstanceOf(DurableExecutionResult::class, $durable);
        $this->assertEquals($expectedResult, $operations->resolveResult($durable));
        $redeliveryBus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $redeliveryBus->expects($this->once())->method('dispatch')->with($this->callback(function (object $message) use ($durable): bool {
            $this->assertEquals($durable, $message, 'Redelivery notifies the original result, without resolving or invoking again.');

            return true;
        }))->willReturn(new Envelope($durable));
        $redeliveryGate = new ExecutionAuthorizationMiddleware(
            $operations,
            $redeliveryBus,
            $container->get(DeferredToolCompletionRepositoryInterface::class),
            [
                ExecuteLlmStep::class => 'llm',
                ExecuteCompactionStep::class => 'llm',
                ExecuteShellToolCall::class => 'tool',
                ExecuteToolCall::class => 'tool',
            ],
        );
        $redeliveryGate->handle($received, new StackMiddleware($invoker));
        $transport->ack($queued[0]);
    }

    public function testHandlerCannotAcknowledgeWithoutDurableResultAndRunningRedeliveryDoesNotInvokeAgain(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('missing execution result');
        $request = new ExecuteShellToolCall($run, 1, 'invoke', 1, 'call', 'printf safe', true);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $operations = $container->get(ExecutionOperationStoreInterface::class);
        $authorization = $operations->arm($request, $pending);
        $reference = $operations->requestReference($request, $authorization);
        $store->finalizeVerifiedTransition($run, $pending->identity);
        $received = new Envelope($reference, [$authorization, new ReceivedStamp('tool')]);
        $invoker = $this->createMock(MiddlewareInterface::class);
        $invoker->expects($this->once())->method('handle')->willReturnArgument(0);
        $gate = $container->get(ExecutionAuthorizationMiddleware::class);
        try {
            $gate->handle($received, new StackMiddleware($invoker));
            $this->fail('An invocation without a durable result must not acknowledge.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Execution handler returned without a durable result.', $exception->getMessage());
        }
        $gate->handle($received, new StackMiddleware($invoker));
        $this->assertNull($operations->claim($reference, $authorization));
    }

    #[DataProvider('kinds')]
    public function testReceivedReferenceWithoutAuthorizationCannotReachInvoker(string $kind): void
    {
        $type = match ($kind) {
            'llm' => ExecuteLlmStep::class,
            'compaction' => ExecuteCompactionStep::class,
            'shell' => ExecuteShellToolCall::class,
            'tool' => ExecuteToolCall::class,
        };
        $reference = new ExecutionRequest('missing', 1, 'step', 1, 'identity', str_repeat('a', 64), $type, str_repeat('b', 64), 100);
        $invoker = $this->createMock(MiddlewareInterface::class);
        $invoker->expects($this->never())->method('handle');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no owner authorization identity');
        self::getContainer()->get(ExecutionAuthorizationMiddleware::class)->handle(new Envelope($reference, [new ReceivedStamp('llm')]), new StackMiddleware($invoker));
    }

    #[DataProvider('deferredCompletionOrders')]
    public function testDeferredApprovalCompletionPublishesAuthorizedInvocationAndReplaysNotification(string $order): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('deferred ledger completion');
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $operations = $container->get(ExecutionOperationStoreInterface::class);
        $deferred = $container->get(DeferredToolCompletionRepositoryInterface::class);

        $suspension = new ExecuteToolCall($run, 1, 'tools', 1, 'i0-key', 'call-1', 'subagent', ['prompt' => 'approve'], 0);
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$suspension]]);
        $pending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $suspensionAuth = $operations->arm($suspension, $pending);
        $suspensionRef = $operations->requestReference($suspension, $suspensionAuth);
        $events->finalizeVerifiedTransition($run, $pending->identity);
        $suspensionClaim = $operations->claim($suspensionRef, $suspensionAuth);
        $this->assertIsString($suspensionClaim);
        $question = \Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO::toolCallFromPayload(
            ['question_id' => 'q-1', 'prompt' => 'Allow?'],
            ['run_id' => $run, 'turn_no' => 1, 'step_id' => 'tools', 'tool_call_id' => 'call-1'],
        );
        // The common ledger seals against the authorized invocation identity.
        $suspensionResult = (new ToolCallResult(
            $run, 1, 'tools', 1, 'i0-key', 'call-1', 0, null, false, null, $question,
        ))->finalized();
        $operations->saveResult($suspension, $suspensionAuth, $suspensionClaim, $suspensionResult);

        $approved = new ExecuteToolCall($run, 1, 'tools', 2, 'i1-key', 'call-1', 'subagent', ['prompt' => 'approved'], 0, humanInputAnswer: new \Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO('q-1', ['approved' => true], ['run_id' => $run], ['hook' => 'approval']));
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$approved]]);
        $pending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $auth = $operations->arm($approved, $pending);
        $reference = $operations->requestReference($approved, $auth);
        $events->finalizeVerifiedTransition($run, $pending->identity);
        $claim = $operations->claim($reference, $auth);
        $this->assertIsString($claim);

        $deferredId = 'def-'.$order;
        $correlation = $deferred->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $deferredId,
            runId: $run,
            turnNo: 1,
            stepId: 'tools',
            attempt: 2,
            idempotencyKey: 'i1-key',
            toolCallId: 'call-1',
            toolName: 'subagent',
            arguments: ['prompt' => 'approved'],
            orderIndex: 0,
        ));
        $terminal = ToolCallResultFactory::fromDeferredCorrelationAndCompletion(
            $correlation,
            [['type' => 'text', 'text' => 'child done']],
        );
        $this->assertSame('i1-key', $terminal->idempotencyKey());
        $this->assertSame('call-1', $terminal->toolCallId);

        $first = null;
        if ('transfer-first' === $order) {
            $operations->transferToDeferred($reference, $auth, $claim, $deferredId);
            $first = $operations->saveDeferredResult($deferredId, $terminal);
            $operations->transferToDeferred($reference, $auth, $claim, $deferredId);
        } else {
            $first = $operations->saveDeferredResult($deferredId, $terminal);
            $operations->transferToDeferred($reference, $auth, $claim, $deferredId);
        }

        $replay = $operations->saveDeferredResult($deferredId, $terminal);
        $this->assertEquals($first, $replay);
        $this->assertEquals($terminal, $operations->resolveResult($first));

        $conflict = ToolCallResultFactory::fromDeferredCorrelationAndCompletion(
            $correlation,
            [['type' => 'text', 'text' => 'different']],
        );
        try {
            $operations->saveDeferredResult($deferredId, $conflict);
            $this->fail('Conflicting deferred outcomes must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Conflicting durable execution result.', $exception->getMessage());
        }

        $connection = $container->get(\Doctrine\DBAL\Connection::class);
        $row = $connection->fetchAssociative('SELECT state, deferred_id, claim_token, attempt, idempotency_key, logical_tool_call_id FROM execution_operation WHERE effect_id = ?', [$first->effectId]);
        $this->assertIsArray($row);
        $this->assertSame('ResultReady', $row['state']);
        $this->assertSame($deferredId, $row['deferred_id']);
        $this->assertSame($claim, $row['claim_token']);
        $this->assertSame(2, (int) $row['attempt']);
        $this->assertSame('i1-key', $row['idempotency_key']);
        $this->assertSame('call-1', $row['logical_tool_call_id']);
        $this->assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM execution_operation WHERE deferred_id = ? AND state = 'Running'", [$deferredId]));
    }

    public static function deferredCompletionOrders(): iterable
    {
        yield 'transfer before completion' => ['transfer-first'];
        yield 'completion before transfer' => ['completion-first'];
    }
}
