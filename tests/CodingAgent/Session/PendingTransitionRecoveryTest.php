<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutionAuthorizationInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class PendingTransitionRecoveryTest extends IsolatedKernelTestCase
{
    public static function executionKinds(): iterable
    {
        yield 'LLM' => ['llm'];
        yield 'compaction' => ['compaction'];
        yield 'shell' => ['shell'];
    }

    #[DataProvider('executionKinds')]
    public function testRecoveryArmsOriginalSerializedEffectAndRunningClaimCannotBeRepeated(string $kind): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('pending execution');
        $request = $this->request($kind, $run);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $operations = $container->get(ExecutionOperationStoreInterface::class);
        // Leave finalization unfinished after arming. Recovery must reuse
        // this generation, not replace it with a newly authorized execution.
        $original = $operations->arm($request, $pending);
        $dispatched = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(static function (object $message, array $stamps) use (&$dispatched): Envelope {
            return $dispatched = new Envelope($message, $stamps);
        });
        $this->recovery($bus)->recover($run);
        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertInstanceOf(Envelope::class, $dispatched);
        $this->assertInstanceOf(ExecutionRequest::class, $dispatched->getMessage());
        $this->assertEquals($original, $dispatched->last(ExecutionAuthorizationStamp::class));
        $serializer = $container->get('messenger.transport.native_php_serializer');
        $this->assertInstanceOf(SerializerInterface::class, $serializer);
        $restored = $serializer->decode($serializer->encode($dispatched));
        $stamp = $restored->last(ExecutionAuthorizationStamp::class);
        $this->assertInstanceOf(ExecutionAuthorizationStamp::class, $stamp);
        $delivery = $restored->getMessage();
        $this->assertInstanceOf(ExecutionRequest::class, $delivery);
        $claim = $operations->claim($delivery, $stamp);
        $this->assertIsString($claim);
        $this->assertEquals($request, $operations->resolveRequest($delivery, $stamp, $claim));
        $this->assertNull($operations->claim($delivery, $original));
        $this->recovery($bus)->recover($run);
    }

    public static function dispositions(): iterable
    {
        foreach (['llm', 'compaction', 'shell'] as $kind) {
            foreach (['Consumed', 'Stale'] as $disposition) {
                yield $kind.' '.$disposition => [$kind, $disposition];
            }
        }
    }

    #[DataProvider('dispositions')]
    public function testZeroEventDispositionRecoveryPersistsOriginalResultDecision(string $kind, string $decision): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('pending result decision');
        $request = $this->request($kind, $run);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $operations = $container->get(ExecutionOperationStoreInterface::class);
        $authorization = $operations->arm($request, $pending);
        $store->finalizeVerifiedTransition($run, $pending->identity);
        $delivery = $operations->requestReference($request, $authorization);
        $claim = $operations->claim($delivery, $authorization);
        $this->assertIsString($claim);
        $result = $this->executionResult($kind, $request);
        $reference = $operations->saveResult($request, $authorization, $claim, $result);
        $this->assertEquals($reference, $operations->claim($delivery, $authorization), 'Redelivery reuses the durable original result.');
        $descriptor = new ExecutionResultDispositionDTO($reference, $decision);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'execution_disposition' => $descriptor]);
        $this->assertFalse($operations->isDisposed($reference));
        $bus = new TestMessageBus();
        $this->recovery($bus)->recover($run);
        $this->assertTrue($operations->isDisposed($reference));
        $this->assertEquals($result, $operations->resolveResult($reference));
        $this->assertEquals($reference, $operations->resultForClaim($delivery, $authorization, $claim), 'Fast owner consumption must not invalidate acknowledgement of an already durable result.');
        $this->assertNull($operations->claim($delivery, $authorization));
        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertNull($store->latestSequenceFor($run), 'A stale decision must not invent canonical events.');
        $this->recovery($bus)->recover($run);
        $this->assertSame([], $bus->messages);
    }

    private function recovery(MessageBusInterface $bus): PendingTransitionRecovery
    {
        $container = self::getContainer();

        return new PendingTransitionRecovery($container->get(PreparedTransitionEventStoreInterface::class), $container->get(ToolExecutionAuthorizationInterface::class), new StepDispatcher($bus, $bus), $container->get(ActiveRunContextInterface::class), $container->get(ExecutionOperationStoreInterface::class));
    }

    private function request(string $kind, string $run): AbstractAgentBusMessage
    {
        return match ($kind) {
            'llm' => new ExecuteLlmStep($run, 1, 'execution', 1, 'original-request', 'tools'),
            'compaction' => new ExecuteCompactionStep($run, 1, 'execution', 1, 'original-request', 'test/model', [], [], [], 0, 0, 0, 0, 'manual'),
            'shell' => new ExecuteShellToolCall($run, 1, 'execution', 1, 'shell-call', 'printf safe', true),
        };
    }

    private function executionResult(string $kind, AbstractAgentBusMessage $request): AbstractAgentBusMessage
    {
        $identity = [$request->runId(), $request->turnNo(), $request->stepId(), $request->attempt(), $request->idempotencyKey()];

        return match ($kind) {
            'llm' => new LlmStepResult(...$identity),
            'compaction' => new CompactionStepResult(...[...$identity, 'original summary', null, [], 0, 0, 0, 0, 'manual']),
            'shell' => new ToolCallResult(...[...$identity, 'shell-call', 0, ['content' => [['type' => 'text', 'text' => 'original output']]]]),
        };
    }
}
