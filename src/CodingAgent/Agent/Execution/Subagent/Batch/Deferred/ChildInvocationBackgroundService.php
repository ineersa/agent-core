<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DTO\ChildBackgroundRequestDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Application\Message\DTO\BackgroundChildInvocationDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Runtime\Contract\DTO\ChildBackgroundCandidateDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class ChildInvocationBackgroundService
{
    public function __construct(
        private DeferredSubagentBatchRepository $batches,
        private DeferredToolCompletionRepositoryInterface $deferred,
        private DenormalizerInterface $serializer,
        private ValidatorInterface $validator,
        private MessageBusInterface $commandBus,
        private RunMessageProcessor $processor,
        private LoggerInterface $logger,
    ) {
    }

    /** @return list<ChildBackgroundCandidateDTO> */
    public function candidates(string $runId): array
    {
        $candidates = [];
        foreach ($this->batches->findUnfinishedByParentRunId($runId) as $batch) {
            if (null !== $batch->backgroundedAt || null !== $batch->interruptionKind || DeferredSubagentBatchLaunchStatusEnum::Launched !== $batch->launchStatus) {
                continue;
            }
            $correlation = $this->deferred->findByDeferredId($batch->lifecycleId);
            if (null === $correlation || !\in_array($correlation->toolName, ['subagent', 'fork'], true) || 'pending' !== $this->deferred->status($batch->lifecycleId)) {
                continue;
            }
            if (!array_any($batch->children, static fn ($child): bool => null === $child->childLifecycleProjection || !$child->childLifecycleProjection->childStatus->isTerminal())) {
                continue;
            }
            $candidates[] = new ChildBackgroundCandidateDTO($batch->lifecycleId, $batch->parentToolCallId, \sprintf('%s %s (%d children)', $correlation->toolName, $batch->parentToolCallId, $batch->totalChildCount));
        }

        return $candidates;
    }

    /** @param array<string, mixed> $payload */
    public function request(string $runId, array $payload): bool
    {
        try {
            $request = $this->serializer->denormalize($payload, ChildBackgroundRequestDTO::class);
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('child_background.invalid_target', ['run_id' => $runId, 'session_id' => $runId, 'component' => 'child_background', 'event_type' => 'child_background.invalid_target', 'exception_class' => $exception::class]);

            return false;
        }
        if (0 !== \count($this->validator->validate($request))) {
            return false;
        }
        foreach ($this->candidates($runId) as $candidate) {
            if ($candidate->lifecycleId === $request->lifecycleId && $candidate->toolCallId === $request->toolCallId) {
                $this->commandBus->dispatch(new BackgroundChildInvocationDTO($runId, $request->lifecycleId, $request->toolCallId));

                return true;
            }
        }

        return false;
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function detach(BackgroundChildInvocationDTO $message): void
    {
        $this->processor->process('background_child', $message);
    }
}
