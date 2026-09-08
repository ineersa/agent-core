<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\Repair\RepairResultNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles repair JSONL commands from the parent TUI/controller process.
 *
 * Runs SessionRepairService through the in-process AgentSessionClient so
 * redrive effects land on the controller's session-scoped Doctrine queues.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class RepairHandler
{
    public function __construct(
        private readonly AgentSessionClient $client,
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
            $result = $this->client->repair($runId, $apply);
            $payload = RepairResultNormalizer::toArray($result);
            $payload['commandId'] = $command->id;
            $payload['commandType'] = $command->type;
            $payload['status'] = 'completed';

            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::SessionRepairCompleted->value,
                runId: $runId,
                seq: 0,
                payload: $payload,
            ));
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
