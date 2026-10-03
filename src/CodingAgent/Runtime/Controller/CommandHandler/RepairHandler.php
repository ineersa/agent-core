<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Domain\Message\RepairSession;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles repair JSONL commands from the parent TUI/controller process.
 *
 * Submits owner work without waiting; run_control emits the correlated reply.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class RepairHandler
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if ('repair' !== $event->command->type) {
            return;
        }

        $command = $event->command;
        $runId = $command->runId ?? '';
        if ('' === $runId) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: '',
                seq: 0,
                payload: ['error' => 'repair requires runId'],
            ));

            return;
        }

        $apply = true;
        if (\array_key_exists('apply', $command->payload)) {
            $apply = (bool) $command->payload['apply'];
        }

        $this->logger->info('Handling repair command', [
            'component' => 'RepairHandler',
            'event_type' => 'session_repair.dispatch',
            'run_id' => $runId,
            'apply' => $apply,
            'command_id' => $command->id,
        ]);

        try {
            $this->commandBus->dispatch(new RepairSession($runId, $apply, $command->id));
        } catch (\Throwable $exception) {
            $this->logger->error('session_repair.controller_failed', [
                'component' => 'RepairHandler',
                'event_type' => 'session_repair.controller_failed',
                'run_id' => $runId,
                'command_id' => $command->id,
                'exception_class' => $exception::class,
                'exception_code' => $exception->getCode(),
            ]);

            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::SessionRepairCompleted->value,
                runId: $runId,
                seq: 0,
                payload: [
                    'commandId' => $command->id,
                    'commandType' => $command->type,
                    'status' => 'failed',
                    'exception_class' => $exception::class,
                ],
            ));
        }
    }
}
