<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DTO\ChildBackgroundRequestDTO;
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
        return $this->batches->findBackgroundCandidates($runId);
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
        if (null === $this->batches->findBackgroundCandidate($runId, $request->lifecycleId, $request->toolCallId)) {
            return false;
        }

        $this->commandBus->dispatch(new BackgroundChildInvocationDTO($runId, $request->lifecycleId, $request->toolCallId));

        return true;
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function detach(BackgroundChildInvocationDTO $message): void
    {
        $this->processor->process('background_child', $message);
    }
}
