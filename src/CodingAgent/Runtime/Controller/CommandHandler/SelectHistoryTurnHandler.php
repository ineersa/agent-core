<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RunHistoryPositionChangedEventFactory;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles select_history_turn JSONL commands from the parent TUI process.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class SelectHistoryTurnHandler
{
    public function __construct(
        private HistorySelectionServiceInterface $historySelectionService,
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
            $result = $this->historySelectionService->selectPrompt($runId, $targetTurnNo);

            /** @var \Ineersa\AgentCore\Domain\Run\RunState $rebuiltState */
            $rebuiltState = $result['rebuiltState'];
            $positionEventSeq = $result['positionEventSeq'];
            $selectedPromptTurnNo = (int) $result['selectedPromptTurnNo'];
            $editorPromptText = (string) $result['editorPromptText'];

            $event->emit(RunHistoryPositionChangedEventFactory::create(
                $runId,
                $positionEventSeq,
                $rebuiltState->turnNo,
                $selectedPromptTurnNo,
                $editorPromptText,
            ));

            $this->logger->info('select_history_turn_handler.completed', [
                'run_id' => $runId,
                'selected_prompt_turn_no' => $selectedPromptTurnNo,
                'position_turn_no' => $rebuiltState->turnNo,
                'position_event_seq' => $positionEventSeq,
            ]);
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
