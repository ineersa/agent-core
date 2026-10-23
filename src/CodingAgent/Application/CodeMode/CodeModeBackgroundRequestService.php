<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode;

use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeBackgroundRequestDTO;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Application\CodeMode\DTO\StopCodeModeDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Runtime\Contract\DTO\CodeModeBackgroundCandidateDTO;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class CodeModeBackgroundRequestService
{
    public function __construct(
        private CodeModeExecutionRepository $executions,
        private DeferredToolCompletionRepositoryInterface $deferred,
        private RunHistorySourceProviderInterface $histories,
        private DenormalizerInterface $serializer,
        private ValidatorInterface $validator,
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
        private RunMessageProcessor $processor,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function stop(StopCodeModeDTO $message): void
    {
        $this->processor->process('stop_code_mode', $message);
    }

    /** @return list<CodeModeBackgroundCandidateDTO> */
    public function candidates(string $runId): array
    {
        $candidates = [];
        foreach ($this->executions->foregroundCandidates($runId) as $row) {
            $metadata = $this->deferred->findMetadataByDeferredId($row['identity']);
            $source = $this->histories->historySource($runId);
            $current = $source->log->pendingToolInvocation($source->path, $runId, $row['tool_call_id']);
            if (null !== $metadata && null !== $current && $metadata->reference->turnNo === $current->turnNo && $metadata->reference->stepId === $current->stepId) {
                $candidates[] = new CodeModeBackgroundCandidateDTO($row['identity'], $row['tool_call_id']);
            }
        }

        return $candidates;
    }

    /** @param array<string, mixed> $payload */
    public function request(string $runId, array $payload, bool $stop = false): bool
    {
        try {
            $request = $this->serializer->denormalize($payload, CodeModeBackgroundRequestDTO::class);
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('code_mode.invalid_target', ['run_id' => $runId, 'session_id' => $runId, 'component' => 'code_mode', 'event_type' => 'code_mode.invalid_target', 'exception_class' => $exception::class]);

            return false;
        }
        if (0 !== \count($this->validator->validate($request))) {
            return false;
        }
        if ($stop) {
            if (!$this->executions->canStop($runId, $request->identity, $request->toolCallId)) {
                return false;
            }
            $this->commandBus->dispatch(new StopCodeModeDTO($runId, $request->identity, $request->toolCallId));
        } else {
            if (!array_any($this->candidates($runId), static fn (CodeModeBackgroundCandidateDTO $candidate): bool => $candidate->identity === $request->identity && $candidate->toolCallId === $request->toolCallId)) {
                return false;
            }
            $this->commandBus->dispatch(new CodeModeOwnerDTO($runId, $request->identity, $request->toolCallId, true));
        }

        return true;
    }
}
