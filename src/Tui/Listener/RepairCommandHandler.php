<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Session\Repair\RepairResult;
use Ineersa\CodingAgent\Session\Repair\SessionRepairRefusalReasonEnum;
use Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Runtime\TuiSessionState;
use Psr\Log\LoggerInterface;

/**
 * Handler for the /repair slash command on the active session.
 *
 * @internal Registered by RepairCommandRegistrar
 */
final class RepairCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly SessionRepairServiceInterface $repairService,
        private readonly TuiSessionState $state,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        if ('' !== trim($command->args)) {
            return new TranscriptMessage(
                '/repair does not accept arguments.',
                'system',
                'error',
            );
        }

        $runId = $this->state->handle?->runId;
        if (null === $runId || '' === $runId) {
            return new TranscriptMessage(
                'No active session to repair.',
                'system',
                'error',
            );
        }

        try {
            $result = $this->repairService->repair($runId, true);
        } catch (\Throwable $exception) {
            $this->logger->error('session_repair.command_failed', [
                'run_id' => $runId,
                'component' => 'tui.repair_command',
                'event_type' => 'session_repair.command_failed',
                'exception_class' => $exception::class,
                'exception_code' => $exception->getCode(),
            ]);

            return new TranscriptMessage(
                'Session repair failed due to an internal error.',
                'system',
                'error',
            );
        }

        return new TranscriptMessage(
            $this->formatUserMessage($result),
            'system',
            $this->messageStyle($result),
        );
    }

    private function formatUserMessage(RepairResult $result): string
    {
        if (null !== $result->refusalReason) {
            return match ($result->refusalReason) {
                SessionRepairRefusalReasonEnum::DuplicateSequences => 'Session repair refused: duplicate event sequences.',
                SessionRepairRefusalReasonEnum::MissingSequences => 'Session repair refused: missing event sequences.',
                SessionRepairRefusalReasonEnum::ActiveStreaming => 'Session repair refused: the session is actively streaming.',
                SessionRepairRefusalReasonEnum::AmbiguousPendingWork => 'Session repair refused: pending tool work is ambiguous.',
                SessionRepairRefusalReasonEnum::NoEvents => 'Session repair refused: no canonical events were found.',
                SessionRepairRefusalReasonEnum::RunStateUnavailable => 'Session repair refused: run state is unavailable.',
                SessionRepairRefusalReasonEnum::ReplayValidationFailed => 'Session repair refused: replay validation failed.',
            };
        }

        if ($result->staleCancellationRepaired) {
            return 'Session repaired: stale cancellation terminalized.';
        }

        if (!$result->repairableStaleCancellationDetected) {
            return 'No repairable corruption detected.';
        }

        return $result->message;
    }

    private function messageStyle(RepairResult $result): string
    {
        if (null !== $result->refusalReason) {
            return 'error';
        }

        if ($result->staleCancellationRepaired) {
            return 'system';
        }

        return 'muted';
    }
}
