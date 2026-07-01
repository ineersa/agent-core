<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Rewind\FileRewindCheckpointService;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class FileRewindCommandHandler
{
    public function __construct(
        private FileRewindCheckpointService $checkpointService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        $type = $event->command->type;
        if (!\in_array($type, ['file_rewind_restore', 'file_rewind_undo'], true)) {
            return;
        }

        $runId = $event->command->runId ?? '';
        if ('' === $runId) {
            $event->emit($this->error($runId, 'Missing runId'));

            return;
        }

        try {
            if ('file_rewind_restore' === $type) {
                $turnNo = (int) ($event->command->payload['turn_no'] ?? 0);
                if ($turnNo < 1) {
                    throw new \InvalidArgumentException('turn_no required');
                }
                $this->checkpointService->restoreForTurn($runId, $turnNo);
            } else {
                $this->checkpointService->undoLastRestore($runId);
            }

            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::StatusUpdated->value,
                runId: $runId,
                seq: 0,
                payload: ['status' => 'file_rewind_ok', 'command' => $type],
            ));
        } catch (\Throwable $e) {
            $this->logger->error('file_rewind.command_failed', [
                'run_id' => $runId,
                'command' => $type,
                'error' => $e->getMessage(),
            ]);
            $event->emit($this->error($runId, $e->getMessage()));
        }
    }

    private function error(string $runId, string $message): RuntimeEvent
    {
        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::ProtocolError->value,
            runId: $runId,
            seq: 0,
            payload: ['error' => $message],
        );
    }
}
