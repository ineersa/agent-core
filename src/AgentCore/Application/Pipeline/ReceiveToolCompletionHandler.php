<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Tool\ToolCompletionDeliveryVerifier;
use Ineersa\AgentCore\Contract\Tool\ToolCompletionRecipientRepositoryInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\RecordToolCompletionDeliveryDTO;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ReceiveToolCompletion;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionRecipientDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/** The existing owner pipeline supplies the recipient lock and verified commit. */
final readonly class ReceiveToolCompletionHandler implements RunMessageHandler
{
    public function __construct(
        private ToolCompletionRecipientRepositoryInterface $recipients,
        private ToolCompletionDeliveryVerifier $verifier,
        private EventFactory $events,
        private NormalizerInterface $serializer,
        private \Ineersa\AgentCore\Application\Tool\ToolCompletionDeliveryEligibilityPolicy $eligibility,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof ReceiveToolCompletion;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof ReceiveToolCompletion || $message->reference->destinationRunId !== $state->runId) {
            throw new \InvalidArgumentException('Completion recipient does not match its locked owner.');
        }
        $reference = $message->reference;
        $accepted = $this->recipients->findDelivery($state->runId, $reference->messageId);
        // Retained recipient identity survives mailbox consumption. Do not fetch
        // even the source event again when that identity settles a retry/conflict.
        if (null !== $accepted) {
            $accepted->assertSameReference($reference);

            return new HandlerResult();
        }
        $this->verifier->verify($state->runId, $reference);
        $delivery = new ToolCompletionRecipientDTO($reference, $state->turnNo, stateReason: $this->eligibility->ineligibleReason($state, $reference->deliveryFence) ?? 'awaiting_safe_boundary');
        $payload = $this->serializer->normalize($delivery);
        if (!\is_array($payload)) {
            throw new \LogicException('Completion recipient must normalize to a canonical record.');
        }
        $event = $this->events->event($state->runId, $state->lastSeq + 1, $state->turnNo, RunEventTypeEnum::ToolCompletionReceived->value, $payload);
        $continuations = [];
        if (\Ineersa\AgentCore\Domain\Run\RunStatus::Completed === $state->status
            && null === $state->parentRunId && null === $state->currentOperation
            && [] === $state->pendingHumanInputRequests && !$state->isStreaming
            && null === $this->eligibility->ineligibleReason($state, $reference->deliveryFence)) {
            $continuations[] = \Ineersa\AgentCore\Application\Handler\AdvanceRunCoordinationFactory::forCompletion($state, $reference->deliveryFence);
        }

        return new HandlerResult(
            nextState: $state->with(['version' => $state->version + 1, 'lastSeq' => $state->lastSeq + 1]),
            events: [$event],
            postCommitActions: [
                new RecordToolCompletionDeliveryDTO($delivery, receiptEventOrdinal: 0),
                new EnqueueCommandDTO(new PendingCommand($state->runId, CoreCommandKind::ToolCompletion, $reference->messageId, ['reference' => $reference])),
                ...$continuations,
            ],
        );
    }
}
