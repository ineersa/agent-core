<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\AdvanceRunCallbackFactory;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class StartRunHandler implements RunMessageHandler
{
    public function __construct(
        private EventFactory $eventFactory,
        private NormalizerInterface $normalizer,
        private ?MessageBusInterface $commandBus = null,
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

        // The canonical model is committed only by RunStarted. A shell-only
        // lifecycle has no RunStarted event and therefore keeps model null even
        // after reaching Completed; it may still initialize exactly once.
        //
        // If RunStarted already committed but the initial AdvanceRun never left
        // this process (for example projection persistence failed after the
        // event append and Messenger retried StartRun), re-arm that kickoff.
        // Once AdvanceRun has applied a token, stop — the run is past start.
        if (null !== $state->model) {
            if (null !== $state->lastAppliedAdvanceKey || null !== $state->currentOperation) {
                return new HandlerResult();
            }

            $postCommit = [];
            $initialAdvance = $this->initialAdvanceCallback($message->runId(), $state->turnNo, 'start-follow-up');
            if (null !== $initialAdvance) {
                $postCommit[] = $initialAdvance;
            }

            return new HandlerResult(postCommit: $postCommit);
        }

        $messages = [] === $message->payload->messages ? $state->messages : $message->payload->messages;

        $canonicalModel = $this->requireCanonicalModel($message);
        $parentRunId = $this->parentRunIdFromStartPayload($message);

        $nextState = new RunState(
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
            activeStepId: $message->stepId(),
            retryableFailure: false,
            model: $canonicalModel,
            parentRunId: $parentRunId,
        );

        $event = $this->eventFactory->event(
            runId: $message->runId(),
            seq: $nextState->lastSeq,
            turnNo: $nextState->turnNo,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'step_id' => $message->stepId(),
                'payload' => $this->normalizePayload($message),
            ],
        );

        $postCommit = [];
        $initialAdvance = $this->initialAdvanceCallback($message->runId(), $nextState->turnNo, 'start-follow-up');
        if (null !== $initialAdvance) {
            $postCommit[] = $initialAdvance;
        }

        return new HandlerResult(
            nextState: $nextState,
            events: [$event],
            postCommit: $postCommit,
        );
    }

    private function initialAdvanceCallback(string $runId, int $turnNo, string $prefix): ?callable
    {
        if (null === $this->commandBus) {
            return null;
        }

        return AdvanceRunCallbackFactory::create($this->commandBus, $runId, $turnNo, $prefix, 'Failed to dispatch initial AdvanceRun command.');
    }

    private function requireCanonicalModel(StartRun $message): string
    {
        $model = $message->payload->metadata?->model;
        if (!\is_string($model)) {
            throw new \RuntimeException(\sprintf('Cannot start run_id=%s: StartRun payload metadata.model is required.', $message->runId()));
        }

        $model = trim($model);
        if ('' === $model) {
            throw new \RuntimeException(\sprintf('Cannot start run_id=%s: StartRun payload metadata.model must be non-empty.', $message->runId()));
        }

        return $model;
    }

    /**
     * Live StartRun must carry the same bounded parent identity that
     * {@see \Ineersa\AgentCore\Application\Replay\RunStateReducer} derives from
     * run_started.metadata.session so the operational projection is never
     * written as a top-level row for a newly launched child.
     */
    private function parentRunIdFromStartPayload(StartRun $message): ?string
    {
        $session = $message->payload->metadata?->session;
        if (!\is_array($session) || 'agent_child' !== ($session['kind'] ?? null)) {
            return null;
        }

        $rawParent = $session['parent_run_id'] ?? null;
        if (!\is_string($rawParent) || '' === trim($rawParent)) {
            throw new \RuntimeException(\sprintf('Cannot start run_id=%s: agent_child session.parent_run_id is required and must be non-blank.', $message->runId()));
        }

        return trim($rawParent);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(StartRun $message): array
    {
        try {
            $payload = $this->normalizer->normalize(
                $message->payload,
                context: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
            );
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Failed to normalize StartRun payload.', previous: $exception);
        }

        if (!\is_array($payload)) {
            throw new \RuntimeException('StartRun payload normalization must return an array.');
        }

        return $payload;
    }
}
