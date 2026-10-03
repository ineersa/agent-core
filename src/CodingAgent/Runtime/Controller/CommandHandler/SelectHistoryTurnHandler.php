<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Application\Message\SelectHistoryPrompt;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles select_history_turn JSONL commands from the parent TUI process.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class SelectHistoryTurnHandler
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if ('select_history_turn' !== $event->command->type) {
            return;
        }

        $command = $event->command;
        $runId = $command->runId ?? '';

        if ('' === $runId) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: '',
                seq: 0,
                payload: ['error' => 'select_history_turn requires runId'],
            ));

            return;
        }

        $targetTurnNo = $this->resolveTargetTurnNo($command->payload);

        if (null === $targetTurnNo) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $runId,
                seq: 0,
                payload: ['error' => 'select_history_turn requires turn_no in payload'],
            ));

            return;
        }

        try {
            $this->commandBus->dispatch(new SelectHistoryPrompt($runId, $targetTurnNo));
        } catch (\Throwable $e) {
            $this->logger->error('select_history_turn_handler.failed', [
                'run_id' => $runId,
                'target_turn_no' => $targetTurnNo,
                'exception' => $e->getMessage(),
            ]);

            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $runId,
                seq: 0,
                payload: ['error' => \sprintf('History select failed: %s', $e->getMessage())],
            ));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveTargetTurnNo(array $payload): ?int
    {
        $turnNo = $payload['turn_no'] ?? null;

        if (!\is_int($turnNo) || $turnNo < 1) {
            return null;
        }

        return $turnNo;
    }
}
