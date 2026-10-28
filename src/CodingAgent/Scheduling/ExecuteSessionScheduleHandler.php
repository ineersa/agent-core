<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Scheduling;

use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationCodec;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionHumanInputSuspension;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\RunCancellationToken;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Scheduling\DTO\ExecuteSessionScheduleDTO;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

#[AsMessageHandler(bus: 'agent.execution.bus')]
final readonly class ExecuteSessionScheduleHandler
{
    public function __construct(
        private SessionScheduleRepository $schedules,
        private ToolExecutorInterface $executor,
        private RunOperationalStatusReaderInterface $status,
        private MessageBusInterface $commandBus,
        private DenormalizerInterface $serializer,
        private ToolExecutionResultStore $results,
    ) {
    }

    public function __invoke(ExecuteSessionScheduleDTO $message): void
    {
        $definition = $this->schedules->all($message->runId)[$message->id] ?? null;
        if (null === $definition || (null !== $definition->expiresAt && $definition->expiresAt <= Clock::get()->now())) {
            return;
        }
        $status = $this->status->findOperationalStatus($message->runId);
        if (null === $status || \in_array($status->status, [RunStatus::Cancelling, RunStatus::Cancelled, RunStatus::Failed], true)) {
            return;
        }
        $result = $this->executor->execute(new ToolCall(
            toolCallId: $message->invocationId,
            toolName: $definition->tool,
            arguments: $definition->arguments,
            orderIndex: 0,
            runId: $definition->runId,
            context: [
                'turn_no' => $definition->turnNo,
                'tools_ref' => 'schedule',
                'schedule_id' => $definition->id,
                'allows_human_input' => false,
                'retain_output' => true,
                'cancel_token' => new RunCancellationToken($this->status, $definition->runId),
            ],
        ));
        $raw = \is_array($result->details) ? ($result->details['raw_result'] ?? null) : null;
        if ($raw instanceof ToolExecutionHumanInputSuspension) {
            // Never authorize external work on behalf of an unanswered approval.
            // Standalone schedule invocations have no canonical answer/resume batch.
            $text = 'Scheduled action denied: human input is not available for scheduled execution.';
        } else {
            $envelope = new ToolCallResult($definition->runId, $definition->turnNo, $message->invocationId, 1, $message->invocationId, $message->invocationId, 0, [
                'tool_name' => $result->toolName,
                'content' => $result->content,
                'details' => $result->details,
            ], $result->isError);
            $notifications = ModelNotificationCodec::denormalizeFromDetails($this->serializer, $result->details);
            $text = (new AgentMessageNormalizer())->toolMessage($envelope, $notifications)->content[0]['text'];
        }
        $this->commandBus->dispatch(new DeliverBackgroundCompletionDTO(
            $definition->runId,
            'schedule:'.$message->invocationId,
            \sprintf("Scheduled %s result [%s]:\n%s", $definition->tool, $definition->id, $text),
            origin: [
                'schedule_id' => $definition->id,
                'tool_name' => $definition->tool,
                'tool_call_id' => $message->invocationId,
                // Preserve the executor's cancellation outcome across delayed delivery,
                // even if another command has since continued the owner.
                'cancelled' => \is_array($result->details) && (true === ($result->details['cancelled'] ?? false) || true === ($result->details['stale_due_to_cancel'] ?? false)),
            ],
        ));
        $this->results->releaseCompleted($definition->runId, $message->invocationId, $definition->tool, null);
    }
}
