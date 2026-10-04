<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Application\Messenger\ExecutionAuthorizationMiddleware;
use Ineersa\AgentCore\Application\Messenger\ExecutionResultMiddleware;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class ExecutionAuthorizationMiddlewareTest extends IsolatedKernelTestCase
{
    public static function kinds(): iterable
    {
        yield 'LLM' => ['llm'];
        yield 'compaction' => ['compaction'];
        yield 'shell' => ['shell'];
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
        $transport = $container->get('messenger.transport.'.('shell' === $kind ? 'tool' : 'llm'));
        $queued = iterator_to_array($transport->get());
        $this->assertCount(1, $queued);
        $this->assertEquals($reference, $queued[0]->getMessage());
        $serializer = $container->get('messenger.transport.native_php_serializer');
        $encoded = $serializer->encode($queued[0]);
        $this->assertLessThan(3000, \strlen($encoded['body']));
        $this->assertStringNotContainsString('private invocation text', $encoded['body']);
        $received = $serializer->decode($encoded)->with(new ReceivedStamp('shell' === $kind ? 'tool' : 'llm'));
        $invoker = $this->createMock(MiddlewareInterface::class);
        $resultMiddleware = $container->get(ExecutionResultMiddleware::class);
        $notify = $this->createMock(MiddlewareInterface::class);
        $durable = null;
        $expectedResult = null;
        $notify->expects($this->once())->method('handle')->willReturnCallback(function (Envelope $result) use (&$durable): never {
            $durable = $result->getMessage();
            $this->assertInstanceOf(DurableExecutionResult::class, $durable);
            throw new \RuntimeException('notification unavailable');
        });
        $invoker->expects($this->once())->method('handle')->willReturnCallback(function (Envelope $invocation) use ($request, $kind, $resultMiddleware, $notify, &$expectedResult): Envelope {
            $this->assertEquals($request, $invocation->getMessage(), 'The winning worker resolves the exact frozen input.');
            $identity = [$request->runId(), $request->turnNo(), $request->stepId(), $request->attempt(), $request->idempotencyKey()];
            $result = match ($kind) {
                'llm' => new LlmStepResult(...[...$identity, null, [], 'stop']),
                'compaction' => new CompactionStepResult(...[...$identity, 'original summary', null, [], 1, 0, 1, 1000, 'manual']),
                'shell' => new ToolCallResult(...[...$identity, 'shell-call', 0, ['content' => [['type' => 'text', 'text' => 'original output']]]]),
            };
            $expectedResult = $result;

            return $resultMiddleware->handle(new Envelope($result), new StackMiddleware($notify));
        });
        $gate = $container->get(ExecutionAuthorizationMiddleware::class);
        try {
            $gate->handle($received, new StackMiddleware($invoker));
            $this->fail('Notification failure must prevent successful handling.');
        } catch (HandlerFailedException $exception) {
            $this->assertSame('notification unavailable', $exception->getWrappedExceptions()[0]->getMessage());
            $this->assertEquals($reference, $exception->getEnvelope()->getMessage(), 'Broker retry must retain the reference, not the decoded invocation.');
        }
        $this->assertInstanceOf(DurableExecutionResult::class, $durable);
        $this->assertEquals($expectedResult, $operations->resolveResult($durable));
        $gate->handle($received, new StackMiddleware($invoker));
        $results = iterator_to_array($container->get('messenger.transport.run_control')->get());
        $this->assertCount(1, $results);
        $this->assertEquals($durable, $results[0]->getMessage(), 'Redelivery notifies the original result, without resolving or invoking again.');
        $transport->ack($queued[0]);
        $container->get('messenger.transport.run_control')->ack($results[0]);
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
        };
        $reference = new ExecutionRequest('missing', 1, 'step', 1, 'identity', str_repeat('a', 64), $type, str_repeat('b', 64), 100);
        $invoker = $this->createMock(MiddlewareInterface::class);
        $invoker->expects($this->never())->method('handle');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no owner authorization identity');
        self::getContainer()->get(ExecutionAuthorizationMiddleware::class)->handle(new Envelope($reference, [new ReceivedStamp('llm')]), new StackMiddleware($invoker));
    }
}
