<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Reducer;

use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;

final class RunReducer
{
    public function reduce(RunState $state, object $command): ReduceResult
    {
        if ($command instanceof StartRun) {
            return new ReduceResult($this->onStartRun($state, $command), []);
        }

        if ($command instanceof AdvanceRun) {
            return $this->onAdvanceRun($state, $command);
        }

        if ($command instanceof ApplyCommand) {
            return new ReduceResult($this->onApplyCommand($state, $command), []);
        }

        return new ReduceResult($state, []);
    }

    private function onStartRun(RunState $state, StartRun $command): RunState
    {
        $messages = $state->messages;
        if (isset($command->payload['messages']) && \is_array($command->payload['messages'])) {
            $messages = [];

            foreach ($command->payload['messages'] as $serializedMessage) {
                if (!\is_array($serializedMessage)) {
                    continue;
                }

                $message = $this->hydrateMessage($serializedMessage);
                if (null === $message) {
                    continue;
                }

                $messages[] = $message;
            }
        }

        return new RunState(
            runId: $state->runId,
            status: RunStatus::Running,
            version: $state->version + 1,
            turnNo: 0,
            lastSeq: $state->lastSeq + 1,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: $messages,
            activeStepId: $command->stepId(),
        );
    }

    private function onAdvanceRun(RunState $state, AdvanceRun $command): ReduceResult
    {
        if (\in_array($state->status, [RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled], true)) {
            return new ReduceResult($state, []);
        }

        $nextTurnNo = $state->turnNo + 1;
        $nextStepId = $command->stepId();

        $nextState = new RunState(
            runId: $state->runId,
            status: RunStatus::Running,
            version: $state->version + 1,
            turnNo: $nextTurnNo,
            lastSeq: $state->lastSeq + 1,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $state->messages,
            activeStepId: $nextStepId,
        );

        $effect = new ExecuteLlmStep(
            runId: $state->runId,
            turnNo: $nextTurnNo,
            stepId: $nextStepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|llm|%d|%s', $state->runId, $nextTurnNo, $nextStepId)),
            contextRef: \sprintf('hot:run:%s', $state->runId),
            toolsRef: \sprintf('toolset:run:%s:turn:%d', $state->runId, $nextTurnNo),
        );

        return new ReduceResult($nextState, [$effect]);
    }

    private function onApplyCommand(RunState $state, ApplyCommand $command): RunState
    {
        $status = $state->status;
        $errorMessage = $state->errorMessage;
        $messages = $state->messages;

        if ('cancel' === $command->kind) {
            $status = RunStatus::Cancelling;
            $reason = $command->payload['reason'] ?? null;
            $errorMessage = \is_string($reason) ? $reason : 'Run cancelled by command.';
        }

        if ('human_response' === $command->kind && RunStatus::WaitingHuman === $state->status) {
            $status = RunStatus::Running;
        }

        if (\in_array($command->kind, ['steer', 'follow_up'], true)
            && isset($command->payload['message'])
            && \is_array($command->payload['message'])) {
            $message = $this->hydrateMessage($command->payload['message']);
            if (null !== $message) {
                $messages[] = $message;
            }
        }

        return new RunState(
            runId: $state->runId,
            status: $status,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq + 1,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $errorMessage,
            messages: $messages,
            activeStepId: $state->activeStepId,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hydrateMessage(array $payload): ?AgentMessage
    {
        $role = $payload['role'] ?? null;
        $rawContent = $payload['content'] ?? null;

        if (!\is_string($role) || !\is_array($rawContent)) {
            return null;
        }

        $content = [];
        foreach ($rawContent as $contentPart) {
            if (!\is_array($contentPart)) {
                continue;
            }

            $content[] = $contentPart;
        }

        $timestamp = null;
        if (\is_string($payload['timestamp'] ?? null)) {
            try {
                $timestamp = new \DateTimeImmutable($payload['timestamp']);
            } catch (\Throwable) {
            }
        }

        return new AgentMessage(
            role: $role,
            content: $content,
            timestamp: $timestamp,
            name: \is_string($payload['name'] ?? null) ? $payload['name'] : null,
            toolCallId: \is_string($payload['tool_call_id'] ?? null) ? $payload['tool_call_id'] : null,
            toolName: \is_string($payload['tool_name'] ?? null) ? $payload['tool_name'] : null,
            details: $payload['details'] ?? null,
            isError: \is_bool($payload['is_error'] ?? null) ? $payload['is_error'] : false,
            metadata: \is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        );
    }
}
