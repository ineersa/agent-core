<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Orchestrator;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Run\RunHandle;
use Ineersa\AgentCore\Domain\Run\RunId;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The AgentRunner class orchestrates the lifecycle of agent execution runs by translating high-level inputs into discrete, idempotent steps. It manages state transitions for starting, continuing, steering, and canceling runs while ensuring reliable message dispatch via a command bus.
 */
final class AgentRunner implements AgentRunnerInterface
{
    /**
     * Injects the command bus for dispatching core commands.
     */
    public function __construct(private readonly MessageBusInterface $commandBus)
    {
    }

    /**
     * Initiates a new agent run and returns a handle.
     */
    public function start(StartRunInput $input): RunHandle
    {
        $runId = $input->runId ?? (string) RunId::generate();
        $stepId = $this->nextStepId('start');
        $this->dispatch(new StartRun(
            runId: $runId,
            turnNo: 0,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: $this->idempotencyKey($runId, $stepId),
            payload: [
                'system_prompt' => $input->systemPrompt,
                'messages' => array_map($this->serializeMessage(...), $input->messages),
                'metadata' => $input->metadata,
            ],
        ));

        return new RunHandle($runId);
    }

    /**
     * Resumes execution for an existing run ID.
     */
    public function continue(string $runId): void
    {
        $this->applyCoreCommand($runId, CoreCommandKind::Continue, []);
    }

    /**
     * Injects steering message into a specific run.
     */
    public function steer(string $runId, AgentMessage $message): void
    {
        $this->applyCoreCommand($runId, CoreCommandKind::Steer, ['message' => $this->serializeMessage($message)]);
    }

    /**
     * Processes follow-up message for a specific run.
     */
    public function followUp(string $runId, AgentMessage $message): void
    {
        $this->applyCoreCommand($runId, CoreCommandKind::FollowUp, ['message' => $this->serializeMessage($message)]);
    }

    /**
     * Cancels a run with an optional reason.
     */
    public function cancel(string $runId, ?string $reason = null): void
    {
        $payload = null === $reason ? [] : ['reason' => $reason];
        $this->applyCoreCommand($runId, CoreCommandKind::Cancel, $payload);
    }

    /**
     * Submits human answer for a specific question ID.
     */
    public function answerHuman(string $runId, string $questionId, mixed $answer): void
    {
        $this->applyCoreCommand($runId, CoreCommandKind::HumanResponse, [
            'question_id' => $questionId,
            'answer' => $answer,
        ]);
    }

    /**
     * Serializes and dispatches a core command message.
     *
     * @param array<string, mixed> $payload
     */
    private function applyCoreCommand(string $runId, string $kind, array $payload): void
    {
        $stepId = $this->nextStepId($kind);
        $this->dispatch(new ApplyCommand(
            runId: $runId,
            turnNo: 0,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: $this->idempotencyKey($runId, $stepId),
            kind: $kind,
            payload: $payload,
        ));
    }

    /**
     * Converts agent message to serializable array.
     *
     * @return array<string, mixed>
     */
    private function serializeMessage(AgentMessage $message): array
    {
        return $message->toArray();
    }

    /**
     * Generates unique step ID with given prefix.
     */
    private function nextStepId(string $prefix): string
    {
        return \sprintf('%s-%d', $prefix, hrtime(true));
    }

    /**
     * Constructs idempotency key from run and step IDs.
     */
    private function idempotencyKey(string $runId, string $stepId): string
    {
        return hash('sha256', \sprintf('%s|%s', $runId, $stepId));
    }

    /**
     * Sends message to the command bus.
     */
    private function dispatch(object $message): void
    {
        try {
            $this->commandBus->dispatch($message);
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to dispatch command message.', previous: $exception);
        }
    }
}
