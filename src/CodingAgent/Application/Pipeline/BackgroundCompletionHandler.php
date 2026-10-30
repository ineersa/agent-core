<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function Symfony\Component\String\u;

/** Enter native completion admission through normal owner transition recovery. */
#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class BackgroundCompletionHandler
{
    public function __construct(
        private RunLockManager $locks,
        private OwnerRunInitializationMiddleware $initialization,
        private RunMessageProcessor $processor,
        private ProcessStore $processes,
        private BackgroundCompletionReceiptRepository $receipts,
        private RuntimeEventSinkInterface $sink,
    ) {
    }

    public function __invoke(DeliverBackgroundCompletionDTO $message): void
    {
        $runId = $message->runId();
        $this->locks->synchronized($runId, function () use ($message, $runId): void {
            $this->initialization->initializeForOwner($runId, $message);
            $record = null;
            $previousReceipt = $this->receipts->receipt($message->identity);
            if (null !== $message->processRecordId) {
                $record = $this->processes->snapshot($message->processRecordId);
                if (null === $record || $record->sessionId !== $runId || 'background-process-'.$record->id !== $message->identity) {
                    return;
                }
                // Candidate selection and log reads are not admission. Serialize
                // this current decision with explicit stop under the same owner
                // lock. A retained receipt survives a lost acknowledgement.
                // Stop records cause, not suppression: an observed completion still
                // notifies once even when the exit races an explicit stop request.
                if (null === $previousReceipt && (null === $record->finishedAt || null === $record->backgroundedAt || null !== $record->completionNotifiedAt)) {
                    return;
                }
            }
            $this->processor->process('background_completion', $message);
            if (null !== $record && null !== $this->receipts->receipt($message->identity)) {
                // Publication alone is not delivery. Only retained mailbox or
                // forwarding admission acknowledges the native producer.
                $this->processes->markCompletionNotified($record->id, $runId);
                if (null === $previousReceipt) {
                    $this->locks->afterRelease($runId, fn () => $this->sink->emit(new RuntimeEvent(
                        type: RuntimeEventTypeEnum::BackgroundProcessCompleted->value,
                        runId: $runId,
                        seq: 0,
                        payload: ['pid' => $record->pid, 'exit_code' => $record->exitCode, 'status' => $record->status->value, 'command_preview' => u($record->command)->truncate(200, '...')->toString(), 'output_tail' => $message->outputTail ?? ''],
                    )));
                }
            }
        });
    }
}
