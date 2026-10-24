<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Pipeline\LocalMetadataCoordinator;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\DTO\DispatchLocalMetadataMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionRunHandler;
use Ineersa\CodingAgent\Application\Message\DTO\BackgroundCodeModeInvocationDTO;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Application\Pipeline\BackgroundCodeModeInvocationHandler;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Runtime\Messenger\ToolPayloadMiddleware;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class BackgroundCodeModeInvocationHandlerTest extends IsolatedKernelTestCase
{
    #[DataProvider('winners')]
    public function testCallerRaceAndFirstTerminalRetention(bool $ackFirst, bool $ready = true): void
    {
        $container = self::getContainer();
        $runId = $container->get(HatfieldSessionStore::class)->createSession('code mode admission');
        $id = Uuid::v7()->toRfc4122();
        $correlation = new DeferredToolCompletionCorrelation($id, $runId, 1, 'step', 1, 'key', 'call', 'code_mode', [], 0);
        $batches = $container->get(ToolBatchStoreInterface::class);
        TestToolBatchRegistration::registerDeferredInvocation($batches, $correlation);
        $repository = $container->get(DeferredToolCompletionRepository::class);
        $repository->registerPending($correlation);
        if ($ready) {
            $repository->markReady($id);
        }
        $state = new RunState($runId, RunStatus::Running, turnNo: 1, pendingToolCalls: ['call' => false], activeStepId: 'step');
        $request = new BackgroundCodeModeInvocationDTO($runId, $id, 'call');
        $handler = $container->get(BackgroundCodeModeInvocationHandler::class);
        $this->assertSame([], $handler->handle(new BackgroundCodeModeInvocationDTO($runId, $id, 'foreign-call'), $state)->events);
        $this->assertSame([], $handler->handle($request, new RunState('foreign', RunStatus::Running, turnNo: 1, pendingToolCalls: ['call' => false], activeStepId: 'step'))->events);
        $this->assertSame([], $handler->handle($request, new RunState($runId, RunStatus::Running, turnNo: 2, pendingToolCalls: ['call' => false], activeStepId: 'step'))->events);
        if (!$ready) {
            $this->assertSame([], $handler->handle($request, $state)->events);
        }
        if ($ackFirst) {
            $ack = $handler->handle($request, $state);
            $this->assertContains(RunEventTypeEnum::ToolCallAcknowledged->value, array_column($ack->events, 'type'));
            $this->assertNotContains(RunEventTypeEnum::ToolExecutionEnd->value, array_column($ack->events, 'type'));
            $runtime = $container->get(RuntimeEventTranslator::class)->translate($ack->events[0]);
            $this->assertSame(RuntimeEventTypeEnum::ToolCallAcknowledged->value, $runtime->type);
            $this->assertTrue($runtime->payload['backgrounded']);
            $projector = new TranscriptProjector($container->get('event_dispatcher'), new TranscriptProjectionState());
            $projector->accept(new RuntimeEvent(RuntimeEventTypeEnum::ToolExecutionStarted->value, $runId, 1, ['tool_call_id' => 'call', 'tool_name' => 'code_mode']));
            $projector->accept($runtime);
            $card = array_values(array_filter($projector->blocks(), static fn ($block): bool => 'tool_result_call' === $block->id))[0];
            $this->assertTrue($card->meta['backgrounded']);
            $this->assertFalse($card->streaming);
            $replayed = $container->get(RunStateReducer::class)->replay($state, $ack->events);
            $this->assertSame($ack->nextState->pendingToolCalls, $replayed->pendingToolCalls);
            $this->admit($runId, $ack->postCommitActions);
            $this->assertSame('pending', $repository->status($id));
        }
        $result = ToolCallResultFactory::fromDeferredCorrelationAndCompletion($correlation, [['type' => 'text', 'text' => 'FIRST']], isError: !$ready);
        $bus = $container->get('agent.command.bus');
        $this->assertInstanceOf(MessageBusInterface::class, $bus);
        if (!$ackFirst) {
            $failing = new MessageBus([$container->get(ToolPayloadMiddleware::class), new HandleMessageMiddleware(new HandlersLocator([
                ToolPayloadMessage::class => [static fn () => throw new \RuntimeException('send failed after sealing')],
            ]))]);
            try {
                $failing->dispatch($result);
                $this->fail('Publication failure must escape to the caller.');
            } catch (HandlerFailedException $exception) {
                $this->assertStringContainsString('send failed after sealing', $exception->getMessage());
            }
            $this->assertNotNull($repository->findMetadataByDeferredId($id)->acceptedResultReference);
        }
        $bus->dispatch($result);
        $reference = $repository->findMetadataByDeferredId($id)->acceptedResultReference;
        $this->assertNotNull($reference);
        $this->assertSame('pending', $repository->status($id), 'Transport publication is not admission.');
        $bus->dispatch(ToolCallResultFactory::fromDeferredCorrelationAndCompletion($correlation, [['type' => 'text', 'text' => 'SECOND']]));
        $this->assertEquals($reference, $repository->findMetadataByDeferredId($id)->acceptedResultReference);
        $retained = $container->get(ToolPayloadStoreInterface::class)->read($reference);
        $this->assertSame('FIRST', $retained->result['content'][0]['text']);
        $this->assertSame([], $handler->handle($request, $state)->events, 'No ACK after terminal acceptance or an earlier ACK.');
        if ($ackFirst) {
            $transport = $container->get('messenger.transport.run_control');
            $this->assertInstanceOf(InMemoryTransport::class, $transport);
            $notices = array_values(array_filter(array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()), static fn (object $message): bool => $message instanceof DeliverBackgroundCompletionDTO && $message->identity === $id));
            $this->assertCount(2, $notices);
            $this->assertSame(['FIRST', 'FIRST'], array_column($notices, 'text'));
            $delivery = $container->get(BackgroundCompletionRunHandler::class)->handle($notices[0], $ack->nextState);
            $this->admit($runId, $delivery->postCommitActions);
            $this->assertSame('completed', $repository->status($id));
        } else {
            $admission = $container->get(ToolCallResultHandler::class)->handle($retained, $state);
            $this->admit($runId, $admission->postCommitActions);
            $this->assertSame('completed', $repository->status($id));
        }
    }

    public static function winners(): iterable
    {
        yield 'ACK wins' => [true];
        yield 'terminal wins' => [false];
        yield 'startup failure before readiness' => [false, false];
    }

    /** @param list<object> $actions */
    private function admit(string $runId, array $actions): void
    {
        $local = array_values(array_filter($actions, static fn (object $action): bool => $action instanceof FinalizeToolBatchDTO || $action instanceof EnqueueCommandDTO || $action instanceof DispatchLocalMetadataMessageDTO));
        $this->assertNotEmpty($local);
        $verified = new VerifiedTransitionDTO(Uuid::v7()->toRfc4122(), ['run_id' => $runId, 'actions' => $actions]);
        self::getContainer()->get(LocalMetadataCoordinator::class)->apply(new TransitionPlan($runId, $verified, $local, [], []));
    }
}
