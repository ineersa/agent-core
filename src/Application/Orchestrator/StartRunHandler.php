<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Orchestrator;

use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;

final readonly class StartRunHandler implements RunMessageHandler
{
    public function __construct(
        private RunMessageStateTools $stateTools,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof StartRun;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof StartRun) {
            throw new \InvalidArgumentException('StartRunHandler can only handle StartRun messages.');
        }

        $messages = $state->messages;

        if (\is_array($message->payload['messages'] ?? null)) {
            $messages = $this->stateTools->messagesFromPayload($message->payload);
        }

        $nextState = $this->stateTools->copyState($state, [
            'status' => RunStatus::Running,
            'version' => $state->version + 1,
            'turnNo' => 0,
            'lastSeq' => $state->lastSeq + 1,
            'isStreaming' => false,
            'streamingMessage' => null,
            'pendingToolCalls' => [],
            'errorMessage' => null,
            'messages' => $messages,
            'activeStepId' => $message->stepId(),
            'retryableFailure' => false,
        ]);

        $event = $this->stateTools->event(
            runId: $message->runId(),
            seq: $nextState->lastSeq,
            turnNo: $nextState->turnNo,
            type: 'run_started',
            payload: [
                'step_id' => $message->stepId(),
                'payload' => $message->payload,
            ],
        );

        return new HandlerResult(
            nextState: $nextState,
            events: [$event],
        );
    }
}
