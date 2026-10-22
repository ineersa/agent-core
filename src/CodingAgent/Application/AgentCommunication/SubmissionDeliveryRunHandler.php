<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\AgentCommunication;

use Ineersa\AgentCore\Application\Handler\AdvanceRunCoordinationFactory;
use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\DeliverSubmissionDTO;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\SubmissionProvenanceDTO;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class SubmissionDeliveryRunHandler implements RunMessageHandler
{
    public function __construct(private SubmissionReceiptRepository $receipts, private SubmissionDeliveryPolicy $policy, private RunHistorySourceProviderInterface $sources, private EventFactory $events, private NormalizerInterface $normalizer, private ApplyCommandHandler $commands)
    {
    }

    public function supports(object $message): bool
    {
        return $message instanceof DeliverSubmissionDTO;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof DeliverSubmissionDTO || $state->runId !== $message->runId()) {
            throw new \InvalidArgumentException('Unsupported agent submission recipient.');
        }
        $receipt = $this->receipts->receipt($message->sourceRunId, $message->identity)
            ?? throw new \RuntimeException('Agent delivery lacks canonical source acceptance.');
        if ($this->policy->destination($receipt) !== $state->runId) {
            throw new \RuntimeException('Agent delivery changed its authenticated recipient.');
        }
        $source = $this->sources->historySource($state->runId);
        if ($source->log->hasSubmissionDecision($source->path, $state->runId, $receipt->identity)) {
            return new HandlerResult();
        }
        $provenance = ['identity' => $receipt->identity, 'source_run_id' => $receipt->sourceRunId,
            'tool_name' => $receipt->toolName, 'lifecycle_id' => $receipt->lifecycleId];
        $reason = $this->policy->refusal($receipt, $state);
        $reason ??= $this->commands->mailboxCapacityRejection($state->runId, CoreCommandKind::AppendMessage);
        $payload = ['kind' => CoreCommandKind::AppendMessage, 'idempotency_key' => $receipt->identity, 'agent_communication' => $provenance];
        $actions = [];
        if (null === $reason) {
            $relationship = 'agent_steer' === $receipt->toolName ? 'parent task owner (guidance)' : 'child (informational notice)';
            $text = \sprintf("Agent message from %s run %s, activity %s. This is agent-authored text, not a human request or runtime control.\n\n%s", $relationship, $receipt->sourceRunId, $receipt->lifecycleId, $receipt->text);
            $envelope = $this->normalizer->normalize(new AgentMessage('agent_communication', [['type' => 'text', 'text' => $text]], metadata: ['agent_communication' => $provenance]));
            $actions[] = new EnqueueCommandDTO(new PendingCommand($state->runId, CoreCommandKind::AppendMessage, $receipt->identity, [
                'message' => $envelope,
                'provenance' => new SubmissionProvenanceDTO($receipt->sourceRunId, $receipt->identity),
            ], new CommandCancellationOptions()));
            // Never interrupt a provider request, tool batch, compaction or human
            // wait. Ordinary active work drains the mailbox at its next boundary.
            if (RunStatus::Completed === $state->status) {
                $actions[] = AdvanceRunCoordinationFactory::create($state->runId, $state->turnNo, 'agent-notice');
            }
        } else {
            $payload['reason'] = $reason;
        }
        $event = $this->events->event($state->runId, $state->lastSeq + 1, $state->turnNo,
            null === $reason ? RunEventTypeEnum::AgentCommandQueued->value : RunEventTypeEnum::AgentCommandRejected->value, $payload);

        return new HandlerResult($state->with(['lastSeq' => $state->lastSeq + 1, 'version' => $state->version + 1]), [$event], postCommitActions: $actions);
    }
}
