<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundCompletion;

use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Coordination\DTO\DispatchLocalMetadataMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Application\BackgroundCompletion\DTO\BackgroundCompletionProvenanceDTO;
use Ineersa\CodingAgent\Application\BackgroundCompletion\DTO\BackgroundCompletionReceiptDTO;
use Ineersa\CodingAgent\Application\BackgroundCompletion\DTO\RecordBackgroundCompletionDTO;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
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
        $receipt = $this->receipts->receipt($message->identity);
        if (null !== $receipt) {
            // A consumed child admission remains consumed even if the original
            // producer retries after cancellation or an explicit agent_resume.
            if (BackgroundCompletionDispositionEnum::Forwarding !== $receipt->disposition) {
                return new HandlerResult();
            }
            if ($state->runId !== $receipt->destinationRunId) {
                return new HandlerResult(postCommitEffects: [$this->forward($receipt)]);
            }
            $receipt = new BackgroundCompletionReceiptDTO(
                $receipt->identity, $receipt->sourceRunId, $receipt->acceptedDestinationRunId,
                $receipt->destinationRunId, $receipt->rootRunId, $receipt->text,
                $receipt->forwardedText, BackgroundCompletionDispositionEnum::Pending,
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
                'provenance' => new BackgroundCompletionProvenanceDTO($receipt->identity, $receipt->sourceRunId),
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
            ]], $event->createdAt,
        ), $result->events);

        return new HandlerResult($result->nextState, $events, $result->effects, $result->postCommitEffects, [
            new DispatchLocalMetadataMessageDTO(new RecordBackgroundCompletionDTO($receipt)),
            ...$result->postCommitActions,
        ]);
    }

    private function forward(BackgroundCompletionReceiptDTO $receipt): DeliverBackgroundCompletionDTO
    {
        return new DeliverBackgroundCompletionDTO($receipt->destinationRunId, $receipt->identity, $receipt->forwardedText, $receipt->sourceRunId);
    }
}
