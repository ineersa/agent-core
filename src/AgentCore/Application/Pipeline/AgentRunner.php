<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class AgentRunner implements AgentRunnerInterface
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private NormalizerInterface $normalizer,
    ) {
    }

    /**
     * @throws \RuntimeException
     */
    public function start(StartRunInput $input): string
    {
        $runId = $input->runId ?? Uuid::v4()->toRfc4122();
        $stepId = $this->nextStepId('start');

        try {
            $this->commandBus->dispatch(new StartRun(
                runId: $runId,
                turnNo: 0,
                stepId: $stepId,
                attempt: 1,
                idempotencyKey: $this->idempotencyKey($runId, $stepId),
                payload: new StartRunPayload(
                    systemPrompt: $input->systemPrompt,
                    messages: $input->messages,
                    metadata: $input->metadata ?? new RunMetadata(),
                ),
            ));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to dispatch start-run message.', previous: $exception);
        }

        return $runId;
    }

    public function continue(string $runId): void
    {
        $this->applyCoreCommand($runId, CoreCommandKind::Continue, []);
    }

    /**
     * @throws \Symfony\Component\Serializer\Exception\ExceptionInterface
     */
    public function steer(string $runId, AgentMessage $message): void
    {
        $payload = $this->normalizer->normalize(
            $message,
            context: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
        );

        $this->applyCoreCommand($runId, CoreCommandKind::Steer, ['message' => $payload]);
    }

    /**
     * @throws \Symfony\Component\Serializer\Exception\ExceptionInterface
     */
    public function followUp(string $runId, AgentMessage $message): void
    {
        $payload = $this->normalizer->normalize(
            $message,
            context: [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
        );

        $this->applyCoreCommand($runId, CoreCommandKind::FollowUp, ['message' => $payload]);
    }

    public function cancel(string $runId, ?string $reason = null): void
    {
        $payload = null === $reason ? [] : ['reason' => $reason];
        $this->applyCoreCommand($runId, CoreCommandKind::Cancel, $payload);
    }

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

        try {
            $this->commandBus->dispatch(new ApplyCommand(
                runId: $runId,
                turnNo: 0,
                stepId: $stepId,
                attempt: 1,
                idempotencyKey: $this->idempotencyKey($runId, $stepId),
                kind: $kind,
                payload: $payload,
            ));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to dispatch command message.', previous: $exception);
        }
    }

    private function nextStepId(string $prefix): string
    {
        return \sprintf('%s-%d', $prefix, hrtime(true));
    }

    private function idempotencyKey(string $runId, string $stepId): string
    {
        return hash('sha256', \sprintf('%s|%s', $runId, $stepId));
    }
}
