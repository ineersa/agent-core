<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface;
use Ineersa\AgentCore\Domain\Message\RepairSession;
use Ineersa\AgentCore\Domain\Message\SelectHistoryPrompt;
use Ineersa\CodingAgent\Runtime\Contract\RepairResult;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Protocol\RunHistoryPositionChangedEventFactory;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink;
use Ineersa\CodingAgent\Session\Repair\RepairResultNormalizer;
use Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class SessionMaintenanceHandler
{
    public function __construct(
        private HistorySelectionServiceInterface $history,
        private SessionRepairServiceInterface $repair,
        private InMemoryRuntimeEventSink $sink,
        private StdoutRuntimeEventSink $stdoutSink,
        #[Autowire('%env(bool:HATFIELD_CONSUMER_STDOUT_EVENTS)%')]
        private bool $consumerStdoutEvents,
        private \Psr\Log\LoggerInterface $logger,
        private \Ineersa\AgentCore\Contract\ActiveRunContextInterface $registry,
        private \Ineersa\AgentCore\Contract\AgentRunnerInterface $runner,
        private \Ineersa\CodingAgent\Session\HatfieldSessionStore $sessions,
        #[Autowire(service: 'agent.command.bus')]
        private \Symfony\Component\Messenger\MessageBusInterface $commandBus,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function attach(\Ineersa\AgentCore\Domain\Message\AttachRun $command): void
    {
        $state = $this->registry->requireLoaded($command->runId);
        if (\Ineersa\AgentCore\Domain\Run\RunStatus::WaitingHuman === $state->status || [] !== $state->pendingHumanInputRequests) {
            $this->runner->cancel($command->runId, 'Outstanding human questions cancelled on session attach.');
        }
        $this->sessions->resetReasoningBaseline($command->runId);
        $this->commandBus->dispatch(new \Ineersa\AgentCore\Domain\Message\RefreshRunContext($command->runId, $command->messages));
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function select(SelectHistoryPrompt $command): RuntimeEvent
    {
        try {
            $result = $this->history->selectPrompt($command->runId, $command->turnNo);
            $event = RunHistoryPositionChangedEventFactory::create($command->runId, $result['positionEventSeq'], $result['rebuiltState']->turnNo, $result['selectedPromptTurnNo'], $result['editorPromptText']);
        } catch (\Throwable $exception) {
            $this->logger->warning('history.selection.failed', ['run_id' => $command->runId, 'component' => 'session.maintenance', 'event_type' => 'history.selection.failed', 'exception_class' => $exception::class]);
            $event = new RuntimeEvent(type: RuntimeEventTypeEnum::ProtocolError->value, runId: $command->runId, seq: 0, payload: ['error' => 'History select failed: '.$exception->getMessage()]);
        }
        $this->emit($event);

        return $event;
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function repair(RepairSession $command): RepairResult
    {
        try {
            $result = $this->repair->repair($command->runId, $command->apply);
        } catch (\Throwable $exception) {
            $this->emit(new RuntimeEvent(type: RuntimeEventTypeEnum::SessionRepairCompleted->value, runId: $command->runId, seq: 0, payload: ['commandId' => $command->commandId, 'commandType' => 'repair', 'status' => 'failed', 'exception_class' => $exception::class]));
            throw $exception;
        }
        $this->emit(new RuntimeEvent(type: RuntimeEventTypeEnum::SessionRepairCompleted->value, runId: $command->runId, seq: 0, payload: RepairResultNormalizer::toArray($result) + ['commandId' => $command->commandId, 'commandType' => 'repair', 'status' => 'completed']));

        return $result;
    }

    private function emit(RuntimeEvent $event): void
    {
        if ($this->consumerStdoutEvents) {
            $this->stdoutSink->emit($event);
        } else {
            $this->sink->emit($event);
        }
    }
}
