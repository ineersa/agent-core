<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Session\Repair\RepairResult;
use Ineersa\CodingAgent\Session\Repair\SessionRepairRefusalReasonEnum;
use Ineersa\CodingAgent\Session\Repair\SessionRepairService;
use Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Listener\RepairCommandHandler;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class RepairCommandHandlerTest extends TestCase
{
    #[Test]
    public function rejectsArguments(): void
    {
        $handler = new RepairCommandHandler($this->createRepairService(), new TuiSessionState('repair'), new NullLogger());

        $result = $handler->handle(new SlashCommand('repair', 'apply', '/repair apply'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('/repair does not accept arguments.', $result->text);
        $this->assertSame('error', $result->style);
    }

    #[Test]
    public function returnsNoActiveSessionWhenRunIdMissing(): void
    {
        $handler = new RepairCommandHandler($this->createRepairService(), new TuiSessionState('repair'), new NullLogger());

        $result = $handler->handle(new SlashCommand('repair', '', '/repair'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('No active session to repair.', $result->text);
    }

    #[Test]
    public function mapsTypedRefusalToSafeUserMessage(): void
    {
        $service = $this->createStub(SessionRepairServiceInterface::class);
        $service->method('repair')->willReturn(new RepairResult(
            repairableStaleCancellationDetected: true,
            staleCancellationRepaired: false,
            terminalEventsAppended: 0,
            replayOk: false,
            message: 'internal',
            duplicateSeqs: [3],
            refusalReason: SessionRepairRefusalReasonEnum::DuplicateSequences,
        ));

        $state = new TuiSessionState('repair');
        $state->handle = new RunHandle('run-1');
        $handler = new RepairCommandHandler($service, $state, new NullLogger());

        $result = $handler->handle(new SlashCommand('repair', '', '/repair'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Session repair refused: duplicate event sequences.', $result->text);
        $this->assertSame('error', $result->style);
    }

    #[Test]
    public function reportsActiveOperationRedrive(): void
    {
        $service = $this->createStub(SessionRepairServiceInterface::class);
        $service->method('repair')->willReturn(new RepairResult(false, false, 0, true, 'internal', activeOperationsRedriven: 1));
        $state = new TuiSessionState('repair');
        $state->handle = new RunHandle('run-redrive');
        $handler = new RepairCommandHandler($service, $state, new NullLogger());

        $result = $handler->handle(new SlashCommand('repair', '', '/repair'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Session repaired: active operation redriven.', $result->text);
        $this->assertSame('system', $result->style);
    }

    #[Test]
    public function logsStructuredDegradationWhenRepairThrows(): void
    {
        $service = $this->createStub(SessionRepairServiceInterface::class);
        $service->method('repair')->willThrowException(new \RuntimeException('corrupt json with secrets'));

        $logger = new TestLogger();
        $state = new TuiSessionState('repair');
        $state->handle = new RunHandle('run-err');
        $handler = new RepairCommandHandler($service, $state, $logger);

        $result = $handler->handle(new SlashCommand('repair', '', '/repair'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Session repair failed due to an internal error.', $result->text);
        $this->assertCount(1, $logger->records);
        $this->assertSame('session_repair.command_failed', $logger->records[0]['message']);
        $this->assertSame('run-err', $logger->records[0]['context']['run_id']);
        $this->assertSame(\RuntimeException::class, $logger->records[0]['context']['exception_class']);
        $this->assertArrayNotHasKey('exception', $logger->records[0]['context']);
        $this->assertArrayNotHasKey('exception_message', $logger->records[0]['context']);
    }

    private function createRepairService(): SessionRepairService
    {
        return new SessionRepairService(
            eventStore: new InMemoryEventStore(),
            runStore: new InMemoryRunStore(),
            runStateReducer: new RunStateReducer(AttributeSerializerValidatorTestFactory::denormalizer(), new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())),
            replayEventPreparer: new ReplayEventPreparer(),
            eventFactory: new EventFactory(),
            toolCallSequenceValidator: new AgentMessageToolCallSequenceValidator(),
            lockManager: new RunLockManager(new LockFactory(new FlockStore(sys_get_temp_dir()))),
            logger: new NullLogger(),
            stepDispatcher: new StepDispatcher(new TestMessageBus()),
            toolBatchStore: $this->createStub(ToolBatchStoreInterface::class),
            serializer: AttributeSerializerValidatorTestFactory::create()[0],
        );
    }
}
