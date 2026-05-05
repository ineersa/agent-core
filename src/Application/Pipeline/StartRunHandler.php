<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class StartRunHandler implements RunMessageHandler
{
    public function __construct(
        private RunMessageStateTools $stateTools,
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

        $messages = [] === $message->payload->messages ? $state->messages : $message->payload->messages;

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
        );

        $event = $this->stateTools->event(
            runId: $message->runId(),
            seq: $nextState->lastSeq,
            turnNo: $nextState->turnNo,
            type: 'run_started',
            payload: [
                'step_id' => $message->stepId(),
                'payload' => $this->normalizePayload($message),
            ],
        );

        $postCommit = [];
        $initialAdvance = $this->initialAdvanceCallback($message->runId(), 'start-follow-up');
        if (null !== $initialAdvance) {
            $postCommit[] = $initialAdvance;
        }

        return new HandlerResult(
            nextState: $nextState,
            events: [$event],
            postCommit: $postCommit,
        );
    }

    private function initialAdvanceCallback(string $runId, string $prefix): ?callable
    {
        if (null === $this->commandBus) {
            return null;
        }

        return function () use ($runId, $prefix): void {
            $stepId = \sprintf('%s-%d', $prefix, hrtime(true));

            try {
                $this->commandBus->dispatch(new AdvanceRun(
                    runId: $runId,
                    turnNo: 0,
                    stepId: $stepId,
                    attempt: 1,
                    idempotencyKey: hash('sha256', \sprintf('%s|%s', $runId, $stepId)),
                ));
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to dispatch initial AdvanceRun command.', previous: $exception);
            }
        };
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
