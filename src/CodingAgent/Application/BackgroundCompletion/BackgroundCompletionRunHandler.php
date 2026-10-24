<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundCompletion;

use Ineersa\AgentCore\Application\Handler\AdvanceRunCoordinationFactory;
use Ineersa\AgentCore\Application\Handler\CommandMailboxCoordinationFactory;
use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Coordination\DTO\DispatchLocalMetadataMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Application\BackgroundCompletion\DTO\BackgroundCompletionProvenanceDTO;
use Ineersa\CodingAgent\Application\BackgroundCompletion\DTO\BackgroundCompletionReceiptDTO;
use Ineersa\CodingAgent\Application\BackgroundCompletion\DTO\RecordBackgroundCompletionDTO;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionReasonEnum;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Native policy runs only after RunMessageProcessor recovers pending work. */
final readonly class BackgroundCompletionRunHandler implements RunMessageHandler
{
    public function __construct(
        private BackgroundCompletionReceiptRepository $receipts,
        private AgentChildRunDirectory $children,
        private ApplyCommandHandler $commands,
        private NormalizerInterface $normalizer,
        private ValidatorInterface $validator,
        private CommandStoreInterface $mailbox,
        private BackgroundCompletionConsumptionPolicy $consumption,
        private EventFactory $events,
        private CodeModeExecutionRepository $codeModeExecutions,
        private DeferredToolCompletionRepositoryInterface $deferred,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof DeliverBackgroundCompletionDTO;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof DeliverBackgroundCompletionDTO) {
            throw new \InvalidArgumentException('Unsupported background completion message.');
        }
        $errors = $this->validator->validate($message);
        if (\count($errors) > 0) {
            throw new ValidationFailedException($message, $errors);
        }
        if ($this->deliveryBlocked($message)) {
            return new HandlerResult();
        }
        $receipt = $this->receipts->receipt($message->identity);
        if (null !== $receipt) {
            if (BackgroundCompletionDispositionEnum::Pending === $receipt->disposition
                && $receipt->destinationRunId === $state->runId
                && null !== $state->parentRunId && $state->status->isTerminal()) {
                return $this->retireTerminalPending($receipt, $state);
            }
            if ($message->explicitContinuation && BackgroundCompletionDispositionEnum::Pending === $receipt->disposition) {
                if ($receipt->destinationRunId !== $state->runId) {
                    return new HandlerResult(postCommitEffects: [$this->forward($receipt, true)]);
                }

                return new HandlerResult(postCommitActions: [AdvanceRunCoordinationFactory::create($state->runId, $state->turnNo, 'repair-background-completion')]);
            }
            // A consumed child admission remains consumed even if the original
            // producer retries after cancellation or an explicit agent_resume.
            if (BackgroundCompletionDispositionEnum::Forwarding !== $receipt->disposition) {
                return new HandlerResult();
            }
            if ($state->runId !== $receipt->destinationRunId) {
                return new HandlerResult(postCommitEffects: [$this->forward($receipt, $message->explicitContinuation)]);
            }
            $receipt = new BackgroundCompletionReceiptDTO(
                $receipt->identity, $receipt->sourceRunId, $receipt->acceptedDestinationRunId,
                $receipt->destinationRunId, $receipt->rootRunId, $receipt->text,
                $receipt->forwardedText, BackgroundCompletionDispositionEnum::Pending,
                $receipt->origin, $receipt->deferredId, $receipt->sourceToolCallId,
                $receipt->sourceTurnNo, $receipt->sourceStepId, $receipt->sourceAttempt, $receipt->sourceIdempotencyKey,
            );
        } else {
            if (null !== $message->sourceRunId && $message->sourceRunId !== $state->runId) {
                throw new \RuntimeException('Forwarded background completion has no durable source receipt.');
            }
            $owner = null === $state->parentRunId ? null : $this->children->locate($state->runId);
            if (null !== $state->parentRunId && null === $owner) {
                throw new \RuntimeException('Background completion child owner identity is missing.');
            }
            // Native children have one immediate top-level owner. Attribution is
            // frozen with the source, never rebuilt from a later resumed attempt.
            $rootRunId = $owner->parentRunId ?? $state->runId;
            $forwardedText = null === $owner ? $message->text : \sprintf(
                "Background completion from child owner %s (agent_id: %s, run_id: %s). The child was not resumed.\n\n%s",
                $owner->agentName, $owner->artifactId, $state->runId, $message->text,
            );
            $forward = null !== $state->parentRunId && ($state->status->isTerminal() || RunStatus::Cancelling === $state->status);
            $destination = $forward ? $rootRunId : $state->runId;
            $receipt = new BackgroundCompletionReceiptDTO(
                $message->identity, $state->runId, $destination, $destination,
                $rootRunId, $message->text, $forwardedText,
                $forward ? BackgroundCompletionDispositionEnum::Forwarding : BackgroundCompletionDispositionEnum::Pending,
                $message->origin, $message->deferredId, $message->sourceToolCallId,
                $message->sourceTurnNo, $message->sourceStepId, $message->sourceAttempt, $message->sourceIdempotencyKey,
            );
            if ($forward) {
                // The durable obligation precedes the best-effort send. Native
                // completion polling retries it until root mailbox admission.
                return new HandlerResult(
                    postCommitEffects: [$this->forward($receipt)],
                    postCommitActions: [new DispatchLocalMetadataMessageDTO(new RecordBackgroundCompletionDTO($receipt))],
                );
            }
        }

        $text = $state->runId === $receipt->sourceRunId ? $receipt->text : $receipt->forwardedText;
        $payload = $this->normalizer->normalize(new AgentMessage('user', [['type' => 'text', 'text' => $text]]), context: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);
        $result = $this->commands->handle(new ApplyCommand(
            $state->runId, $state->turnNo, $receipt->identity, 1, $receipt->identity,
            CoreCommandKind::AppendMessage, [
                'message' => $payload,
                'provenance' => new BackgroundCompletionProvenanceDTO(
                    $receipt->identity,
                    $receipt->sourceRunId,
                    $receipt->origin,
                    $receipt->deferredId,
                    $receipt->sourceToolCallId,
                    $receipt->sourceTurnNo,
                    $receipt->sourceStepId,
                    $receipt->sourceAttempt,
                    $receipt->sourceIdempotencyKey,
                ),
            ],
        ), $state);
        if (!array_any($result->postCommitActions, static fn (object $action): bool => $action instanceof EnqueueCommandDTO)) {
            // Mailbox refusal must not acknowledge admission. A forwarding
            // obligation remains outstanding for the next native poll.
            return $result;
        }

        $events = array_map(static fn (RunEvent $event): RunEvent => new RunEvent(
            $event->runId, $event->seq, $event->turnNo, $event->type,
            [...$event->payload, 'background_completion' => [
                'identity' => $receipt->identity,
                'source_run_id' => $receipt->sourceRunId,
                'accepted_destination_run_id' => $receipt->acceptedDestinationRunId,
                'destination_run_id' => $receipt->destinationRunId,
                'origin' => $receipt->origin->value,
                'deferred_id' => $receipt->deferredId,
                'source_tool_call_id' => $receipt->sourceToolCallId,
                'source_turn_no' => $receipt->sourceTurnNo,
                'source_step_id' => $receipt->sourceStepId,
                'source_attempt' => $receipt->sourceAttempt,
                'source_idempotency_key' => $receipt->sourceIdempotencyKey,
            ]], $event->createdAt,
        ), $result->events);

        return new HandlerResult($result->nextState, $events, $result->effects, $result->postCommitEffects, [
            new DispatchLocalMetadataMessageDTO(new RecordBackgroundCompletionDTO($receipt)),
            ...$result->postCommitActions,
        ]);
    }

    private function deliveryBlocked(DeliverBackgroundCompletionDTO $message): bool
    {
        if (null === $message->deferredId) {
            return false;
        }
        if ($message->explicitContinuation) {
            return false;
        }
        if ($this->deferred->requiresContinuation($message->runId(), $message->sourceToolCallId ?? $message->deferredId)) {
            return true;
        }
        if (BackgroundCompletionOriginEnum::CodeMode !== $message->origin || null === $message->sourceToolCallId) {
            return false;
        }
        $reason = $this->codeModeExecutions->interruption($message->runId(), $message->sourceToolCallId);

        // Controller loss parks through shared continuation_required. Sticky
        // cancellation and history discard remain the only native refusals.
        return null !== $reason && !\in_array($reason, [
            CodeModeInterruptionReasonEnum::ControllerRestarted,
            CodeModeInterruptionReasonEnum::ControllerShutdown,
        ], true);
    }

    private function retireTerminalPending(BackgroundCompletionReceiptDTO $receipt, RunState $state): HandlerResult
    {
        foreach ($this->mailbox->pending($state->runId) as $command) {
            if ($command->idempotencyKey !== $receipt->identity) {
                continue;
            }
            $decision = $this->consumption->prepare($state, $command);
            if ($decision?->defer) {
                return new HandlerResult();
            }
            if (null === $decision || null === $decision->retirementReason) {
                throw new \RuntimeException('Terminal background receipt lacks its native mailbox retirement decision.');
            }
            // Permanent worker failure can retain an old currentOperation, so
            // AdvanceRun may not drain this row. Reuse the same consumption
            // policy and captured mailbox finalization without advancing the run
            // or touching unrelated pending user commands.
            $event = $this->events->event(
                $state->runId, $state->lastSeq + 1, $state->turnNo,
                RunEventTypeEnum::AgentCommandRejected->value,
                [
                    'kind' => $command->kind,
                    'idempotency_key' => $command->idempotencyKey,
                    'reason' => $decision->retirementReason,
                    'consumption_metadata' => $decision->metadata,
                ],
            );

            return CommandMailboxCoordinationFactory::finalize(new HandlerResult(
                $state->with(['version' => $state->version + 1, 'lastSeq' => $state->lastSeq + 1]), [$event],
            ));
        }
        throw new \RuntimeException('Pending background receipt has no pending mailbox entry.');
    }

    private function forward(BackgroundCompletionReceiptDTO $receipt, bool $explicitContinuation = false): DeliverBackgroundCompletionDTO
    {
        return new DeliverBackgroundCompletionDTO(
            $receipt->destinationRunId,
            $receipt->identity,
            $receipt->forwardedText,
            $receipt->origin,
            $receipt->sourceRunId,
            $receipt->deferredId,
            $receipt->sourceToolCallId,
            $receipt->sourceTurnNo,
            $receipt->sourceStepId,
            $receipt->sourceAttempt,
            $receipt->sourceIdempotencyKey,
            $explicitContinuation,
        );
    }
}
