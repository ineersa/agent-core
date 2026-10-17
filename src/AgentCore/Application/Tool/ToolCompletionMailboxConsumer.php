<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Tool;

use Ineersa\AgentCore\Application\Pipeline\CommandApplicationResult;
use Ineersa\AgentCore\Contract\Tool\ToolCompletionRecipientRepositoryInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Coordination\MarkCommandAppliedDTO;
use Ineersa\AgentCore\Domain\Coordination\RecordToolCompletionDeliveryDTO;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationCodec;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionRecipientDTO;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/** Prepares one mailbox decision; only verified metadata finalization mutates stores. */
final readonly class ToolCompletionMailboxConsumer
{
    // Conservatively include the converter's custom-role prefix in the shared
    // provider-bound budget, not only the notification's stored text.
    public const PROVIDER_PREFIX_BYTES = 64;

    public function __construct(
        private ToolCompletionRecipientRepositoryInterface $recipients,
        private ToolCompletionDeliveryEligibilityPolicy $eligibility,
        private ToolCompletionNotificationRenderer $renderer,
        private NormalizerInterface $normalizer,
    ) {
    }

    public function isAppliedContinuation(string $runId, ToolCompletionDeliveryReferenceDTO $reference): bool
    {
        $delivery = $this->recipients->findDelivery($runId, $reference->messageId);
        if (null === $delivery) {
            return false;
        }
        $delivery->assertSameReference($reference);

        return ToolCompletionDeliveryDispositionEnum::Applied === $delivery->disposition;
    }

    public function apply(PendingCommand $command, RunState $state, int $budget): CommandApplicationResult
    {
        $reference = $command->payload['reference'] ?? null;
        if (!$reference instanceof ToolCompletionDeliveryReferenceDTO || $reference->destinationRunId !== $state->runId || $command->idempotencyKey !== $reference->messageId) {
            throw new \RuntimeException('Internal completion instruction has an invalid recipient reference.');
        }
        $delivery = $this->recipients->findDelivery($state->runId, $reference->messageId) ?? throw new \RuntimeException('Internal completion instruction has no accepted recipient.');
        $delivery->assertSameReference($reference);
        if (ToolCompletionDeliveryDispositionEnum::Pending !== $delivery->disposition) {
            return new CommandApplicationResult($state, [], postCommitActions: [new MarkCommandAppliedDTO($state->runId, $reference->messageId)]);
        }
        $reason = $this->eligibility->ineligibleReason($state, $reference->deliveryFence);
        if (null !== $reason) {
            $parked = new ToolCompletionRecipientDTO($reference, $delivery->receivedTurnNo, $delivery->receivedSequence, ToolCompletionDeliveryDispositionEnum::Parked, $reason);

            return new CommandApplicationResult($state, [[
                'type' => RunEventTypeEnum::AgentCommandRejected->value,
                'payload' => ['kind' => CoreCommandKind::ToolCompletion, 'idempotency_key' => $reference->messageId, 'reason' => $reason, 'delivery_disposition' => 'parked'],
            ]], postCommitActions: [new RecordToolCompletionDeliveryDTO($parked, ToolCompletionDeliveryDispositionEnum::Pending)]);
        }
        if (!\in_array($state->status, [RunStatus::Running, RunStatus::Completed], true) || [] !== $state->pendingHumanInputRequests
            || $state->isStreaming || null !== $state->currentOperation
            || [] !== $state->pendingShellToolCalls
            || array_any($state->pendingToolCalls, static fn (bool $done): bool => true !== $done)
            || $budget < ToolCompletionNotificationRenderer::MIN_BYTES + self::PROVIDER_PREFIX_BYTES) {
            return new CommandApplicationResult($state, []);
        }
        $notification = $this->renderer->render($reference, $budget - self::PROVIDER_PREFIX_BYTES);
        $normalized = $this->normalizer->normalize($notification);
        if (!\is_array($normalized)) {
            throw new \LogicException('Completion notification must normalize to an object.');
        }
        $message = new AgentMessage('tool_completion', [['type' => 'text', 'text' => $notification->text]], details: ['model_notifications' => [$normalized]], metadata: $notification->metadata);
        $applied = new ToolCompletionRecipientDTO($reference, $delivery->receivedTurnNo, $delivery->receivedSequence, ToolCompletionDeliveryDispositionEnum::Applied, 'applied_at_safe_boundary', max($state->lastSeq, $delivery->receivedSequence ?? 0) + 1);

        return new CommandApplicationResult($state->with(['messages' => [...$state->messages, $message]]), [
            ['type' => RunEventTypeEnum::AgentCommandApplied->value, 'payload' => ['kind' => CoreCommandKind::ToolCompletion, 'idempotency_key' => $reference->messageId, 'message' => $message->toArray(), 'text' => $notification->text]],
            ...ModelNotificationCodec::toEventSpecs($this->normalizer, [$notification]),
        ], true, postCommitActions: [new RecordToolCompletionDeliveryDTO($applied, ToolCompletionDeliveryDispositionEnum::Pending, appliedEventOrdinal: 0)], completionBytes: \strlen($notification->text) + self::PROVIDER_PREFIX_BYTES);
    }
}
