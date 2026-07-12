<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildArtifactFinalizer;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildHandoffRenderer;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildParentSequenceCoordinator;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildProgressEmitter;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Uid\Uuid;

/** Parallel foreground subagent launch, polling, aggregate progress, and reporting. */
final class ParallelSubagentExecutionService
{
    private const int DEFAULT_POLL_MICROS = 250_000;

    public function __construct(
        private readonly SubagentLaunchPreparationService $launchPreparation,
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly AgentChildRunDirectory $childRunDirectory,
        private readonly AgentRunnerInterface $agentRunner,
        private readonly RunStoreInterface $childRunStore,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly AgentChildProgressEmitter $progressEmitter,
        private readonly AgentChildArtifactFinalizer $artifactFinalizer,
        private readonly AgentChildHandoffRenderer $handoffRenderer,
        private readonly AgentChildParentSequenceCoordinator $sequenceCoordinator,
        private readonly AgentsConfig $agentsConfig,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    /**
     * @param list<SubagentTaskDTO> $tasks
     */
    public function execute(string $parentRunId, array $tasks): string
    {
        // Defense-in-depth: SubagentTool enforces the same cap before calling this method (LLM-visible fail-fast).
        $maxAgents = $this->agentsConfig->maxAgents;
        $taskCount = \count($tasks);
        if ($taskCount > $maxAgents) {
            throw new ToolCallException(\sprintf('Parallel subagent execution supports at most %d agents per tool call, but %d tasks were requested.', $maxAgents, $taskCount), retryable: false, hint: \sprintf('Split the work into multiple subagent calls with at most %d tasks each.', $maxAgents));
        }

        $this->launchPreparation->assertDepthAllowed($parentRunId);

        /** @var list<array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,definition:AgentDefinitionDTO}> $launches */
        $launches = [];
        foreach ($tasks as $index => $taskDto) {
            $agentName = $taskDto->trimmedAgent();
            $taskText = $taskDto->trimmedTask();

            $definition = $this->launchPreparation->requireParallelDefinition($agentName);

            $launches[] = [
                'index' => $index + 1,
                'agentName' => $agentName,
                'task' => $taskText,
                'artifactId' => 'agent_'.bin2hex(random_bytes(8)),
                'agentRunId' => Uuid::v4()->toRfc4122(),
                'definition' => $definition,
            ];
        }

        /** @var array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string}> $reports */
        $reports = [];
        foreach ($launches as $launch) {
            $reports[$launch['agentRunId']] = [
                'index' => $launch['index'],
                'agentName' => $launch['agentName'],
                'task' => $launch['task'],
                'artifactId' => $launch['artifactId'],
                'agentRunId' => $launch['agentRunId'],
                'model' => $launch['definition']->model,
                'terminal' => false,
                'status' => null,
                'message' => '',
            ];
        }

        try {
            foreach ($launches as $launch) {
                $entry = $this->artifactRegistry->create(
                    parentRunId: $parentRunId,
                    artifactId: $launch['artifactId'],
                    agentRunId: $launch['agentRunId'],
                    agentName: $launch['agentName'],
                    kind: AgentArtifactKindEnum::Subagent,
                );
                $this->childRunDirectory->register($entry);

                $prepared = $this->launchPreparation->prepareFromDefinition(
                    $parentRunId,
                    $launch['definition'],
                    $launch['agentName'],
                    $launch['task'],
                    $launch['artifactId'],
                    $launch['agentRunId'],
                );

                $this->agentRunner->start($prepared->startRunInput);

                $this->artifactRegistry->update(
                    parentRunId: $parentRunId,
                    artifactId: $launch['artifactId'],
                    status: AgentArtifactStatusEnum::Running,
                    startedAt: new \DateTimeImmutable(),
                );
            }
        } catch (\Throwable $e) {
            $this->abortParallelLaunch($parentRunId, $reports, $e);

            throw new ToolCallException('Parallel subagent launch failed: '.$e->getMessage()."\n\n".$this->formatParallelReport($reports), retryable: false, previous: $e);
        }

        $timeoutSeconds = $this->agentsConfig->subagentToolTimeoutSeconds;
        $deadline = $this->nowMicros() + $timeoutSeconds * 1_000_000;
        $context = $this->contextAccessor->current();
        $cancelToken = $context?->cancellationToken();
        $progressSeq = $this->sequenceCoordinator->resolveNextProgressSeq($parentRunId);
        $lastProgressSignature = null;
        $parallelProgressStartedMicros = $this->nowMicros();

        while ($this->hasActiveParallelChildren($reports)) {
            if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                foreach ($reports as $agentRunId => $report) {
                    if ($report['terminal']) {
                        continue;
                    }
                    $this->agentRunner->cancel($agentRunId, 'Parent run cancelled parallel subagent tool.');
                    $cancelChildState = $this->childRunStore->get($agentRunId);
                    $this->artifactFinalizer->finalize(
                        parentRunId: $parentRunId,
                        artifactId: $report['artifactId'],
                        status: AgentArtifactStatusEnum::Cancelled,
                        summary: 'Cancelled by parent run.',
                        displayName: $report['agentName'],
                        childRunId: $agentRunId,
                        childState: $cancelChildState,
                    );
                    $reports[$agentRunId]['terminal'] = true;
                    $reports[$agentRunId]['status'] = AgentArtifactStatusEnum::Cancelled;
                    $reports[$agentRunId]['message'] = 'Cancelled by parent run.';
                }

                $this->progressEmitter->emitParallel(
                    $parentRunId,
                    $reports,
                    $this->parallelActiveTurns($reports),
                    $progressSeq,
                    $parallelProgressStartedMicros,
                    aggregateStatus: 'cancelled',
                );
                $this->sequenceCoordinator->advanceParentSequence($parentRunId, $progressSeq);

                throw new ToolCallException("Parallel subagent tool cancelled by parent run.\n\n".$this->formatParallelReport($reports), retryable: false);
            }

            if ($this->nowMicros() > $deadline) {
                foreach ($reports as $agentRunId => $report) {
                    if ($report['terminal']) {
                        continue;
                    }
                    $this->agentRunner->cancel($agentRunId, 'Parallel subagent timed out.');
                    $this->artifactFinalizer->finalize(
                        parentRunId: $parentRunId,
                        artifactId: $report['artifactId'],
                        status: AgentArtifactStatusEnum::Failed,
                        failureReason: 'Child run timed out.',
                        summary: 'Timed out after '.$timeoutSeconds.'s.',
                    );
                    $reports[$agentRunId]['terminal'] = true;
                    $reports[$agentRunId]['status'] = AgentArtifactStatusEnum::Failed;
                    $reports[$agentRunId]['message'] = 'Timed out after '.$timeoutSeconds.'s.';
                }

                throw new ToolCallException(\sprintf('Parallel subagents timed out after %d seconds.', $timeoutSeconds)."\n\n".$this->formatParallelReport($reports), retryable: false);
            }

            /** @var array<string, int> $activeTurns */
            $activeTurns = [];
            foreach ($reports as $agentRunId => $report) {
                if ($report['terminal']) {
                    continue;
                }

                $state = $this->childRunStore->get($agentRunId);
                if (null === $state) {
                    continue;
                }

                $activeTurns[$agentRunId] = $state->turnNo;
                $status = $state->status;
                if (RunStatus::Running === $status || RunStatus::Queued === $status || RunStatus::Compacting === $status) {
                    if (AgentArtifactStatusEnum::NeedsClarification === $reports[$agentRunId]['status']) {
                        $this->artifactRegistry->update(
                            parentRunId: $parentRunId,
                            artifactId: $report['artifactId'],
                            status: AgentArtifactStatusEnum::Running,
                        );
                        $reports[$agentRunId]['status'] = AgentArtifactStatusEnum::Running;
                    }

                    continue;
                }

                match ($status) {
                    RunStatus::WaitingHuman => (function () use ($parentRunId, $agentRunId, $report, &$reports): void {
                        $this->artifactRegistry->update(
                            parentRunId: $parentRunId,
                            artifactId: $report['artifactId'],
                            status: AgentArtifactStatusEnum::NeedsClarification,
                        );
                        $reports[$agentRunId]['status'] = AgentArtifactStatusEnum::NeedsClarification;
                        $reports[$agentRunId]['message'] = 'Waiting for human input.';
                    })(),
                    RunStatus::Completed => (function () use ($parentRunId, $agentRunId, $state, $report, &$reports): void {
                        $summary = $this->handoffRenderer->extractLastMessage($state);
                        $this->artifactFinalizer->finalize(
                            parentRunId: $parentRunId,
                            artifactId: $report['artifactId'],
                            status: AgentArtifactStatusEnum::Completed,
                            summary: $summary,
                        );
                        $reports[$agentRunId]['terminal'] = true;
                        $reports[$agentRunId]['status'] = AgentArtifactStatusEnum::Completed;
                        $reports[$agentRunId]['message'] = $summary;
                    })(),
                    RunStatus::Failed => (function () use ($parentRunId, $agentRunId, $state, $report, &$reports): void {
                        $errorMsg = $state->errorMessage ?? 'Run failed without error message.';
                        $this->artifactFinalizer->finalize(
                            parentRunId: $parentRunId,
                            artifactId: $report['artifactId'],
                            status: AgentArtifactStatusEnum::Failed,
                            failureReason: $errorMsg,
                            summary: $errorMsg,
                        );
                        $reports[$agentRunId]['terminal'] = true;
                        $reports[$agentRunId]['status'] = AgentArtifactStatusEnum::Failed;
                        $reports[$agentRunId]['message'] = $errorMsg;
                    })(),
                    RunStatus::Cancelled, RunStatus::Cancelling => (function () use ($parentRunId, $report, &$reports, $agentRunId, $state): void {
                        $this->artifactFinalizer->finalize(
                            parentRunId: $parentRunId,
                            artifactId: $report['artifactId'],
                            status: AgentArtifactStatusEnum::Cancelled,
                            summary: 'Child run was cancelled.',
                            displayName: $report['agentName'],
                            childRunId: $agentRunId,
                            childState: $state,
                        );
                        $reports[$agentRunId]['terminal'] = true;
                        $reports[$agentRunId]['status'] = AgentArtifactStatusEnum::Cancelled;
                        $reports[$agentRunId]['message'] = 'Child run was cancelled.';
                    })(),
                };
            }

            $signature = $this->progressEmitter->parallelProgressSignature($parentRunId, $reports, $activeTurns);
            if (null === $lastProgressSignature || $signature !== $lastProgressSignature) {
                $this->progressEmitter->emitParallel($parentRunId, $reports, $activeTurns, $progressSeq, $parallelProgressStartedMicros);
                $this->sequenceCoordinator->advanceParentSequence($parentRunId, $progressSeq);
                ++$progressSeq;
                $lastProgressSignature = $signature;
            }

            $this->sleepPollInterval();
        }

        $this->progressEmitter->emitParallel(
            $parentRunId,
            $reports,
            $this->parallelActiveTurns($reports),
            $progressSeq,
            $parallelProgressStartedMicros,
            aggregateStatus: $this->resolveParallelAggregateStatus($reports),
        );
        $this->sequenceCoordinator->advanceParentSequence($parentRunId, $progressSeq);
        ++$progressSeq;

        $failed = array_filter($reports, static fn (array $r): bool => AgentArtifactStatusEnum::Completed !== $r['status']);

        if ([] !== $failed) {
            throw new ToolCallException('Parallel subagent execution failed for one or more children.'."\n\n".$this->formatParallelReport($reports), retryable: false);
        }

        return $this->formatParallelSuccess($reports);
    }

    /**
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string,model?:string}> $reports
     */
    private function hasActiveParallelChildren(array $reports): bool
    {
        foreach ($reports as $report) {
            if (!$report['terminal']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string}> $reports
     */
    private function formatParallelReport(array $reports): string
    {
        $sorted = array_values($reports);
        usort($sorted, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        $lines = [];
        foreach ($sorted as $report) {
            $status = null !== $report['status'] ? $report['status']->value : 'unknown';
            $lines[] = \sprintf('#%d %s — %s', $report['index'], $report['agentName'], $status);
            $lines[] = 'Artifact: '.$report['artifactId'];
            if ('' !== $report['message']) {
                $lines[] = trim($report['message']);
            }
            $lines[] = '';
        }

        $body = rtrim(implode("\n", $lines));
        if ('' === $body) {
            return 'Use agent_retrieve (metadata/events/history) for partial child details.';
        }

        return $body."\n\nUse agent_retrieve (metadata/events/history) for partial child details.";
    }

    /**
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string}> $reports
     */
    private function formatParallelSuccess(array $reports): string
    {
        $sorted = array_values($reports);
        usort($sorted, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);
        $count = \count($sorted);

        $lines = [\sprintf('Parallel subagents completed (%d/%d).', $count, $count), ''];
        foreach ($sorted as $report) {
            $lines[] = \sprintf('#%d %s — completed', $report['index'], $report['agentName']);
            $lines[] = 'Artifact: '.$report['artifactId'];
            if ('' !== $report['message']) {
                $lines[] = trim($report['message']);
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    /**
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string,model?:string}> $reports
     */
    private function abortParallelLaunch(string $parentRunId, array &$reports, \Throwable $cause): void
    {
        $startedCount = 0;
        foreach ($reports as $report) {
            if ($report['terminal']) {
                continue;
            }

            $entry = $this->artifactRegistry->get($parentRunId, $report['artifactId']);
            if (null !== $entry) {
                ++$startedCount;
            }
        }

        $this->logger->warning('subagent_execution.parallel_launch_aborted', [
            'run_id' => $parentRunId,
            'component' => 'agent.execution',
            'event_type' => 'subagent_execution.parallel_launch_aborted',
            'task_count' => \count($reports),
            'started_count' => $startedCount,
            'exception_class' => $cause::class,
        ]);

        $neverLaunchedMessage = 'Child run was not launched after a parallel launch failure.';

        foreach ($reports as $agentRunId => $report) {
            if ($report['terminal']) {
                continue;
            }

            $entry = $this->artifactRegistry->get($parentRunId, $report['artifactId']);
            if (null === $entry) {
                $reports[$agentRunId]['terminal'] = true;
                $reports[$agentRunId]['status'] = AgentArtifactStatusEnum::Failed;
                $reports[$agentRunId]['message'] = $neverLaunchedMessage;

                continue;
            }

            if (\in_array($entry->status, [
                AgentArtifactStatusEnum::Completed,
                AgentArtifactStatusEnum::Failed,
                AgentArtifactStatusEnum::Cancelled,
                AgentArtifactStatusEnum::NeedsClarification,
            ], true)) {
                $reports[$agentRunId]['terminal'] = true;
                $reports[$agentRunId]['status'] = $entry->status;
                $reports[$agentRunId]['message'] = $entry->summary ?? $entry->failureReason ?? $entry->status->value;

                continue;
            }

            if (AgentArtifactStatusEnum::Running === $entry->status) {
                $this->agentRunner->cancel($agentRunId, 'Parallel subagent launch aborted after sibling failure.');
                $this->artifactFinalizer->finalize(
                    parentRunId: $parentRunId,
                    artifactId: $report['artifactId'],
                    status: AgentArtifactStatusEnum::Failed,
                    failureReason: $cause->getMessage(),
                    summary: 'Cancelled after parallel launch failure.',
                );
                $reports[$agentRunId]['terminal'] = true;
                $reports[$agentRunId]['status'] = AgentArtifactStatusEnum::Failed;
                $reports[$agentRunId]['message'] = 'Cancelled after parallel launch failure.';

                continue;
            }

            // Remaining non-terminal launch state is Pending (registry entry created, start() not reached).
            $this->artifactFinalizer->finalize(
                parentRunId: $parentRunId,
                artifactId: $report['artifactId'],
                status: AgentArtifactStatusEnum::Failed,
                failureReason: $cause->getMessage(),
                summary: 'Child run failed to start.',
            );
            $reports[$agentRunId]['terminal'] = true;
            $reports[$agentRunId]['status'] = AgentArtifactStatusEnum::Failed;
            $reports[$agentRunId]['message'] = 'Child run failed to start.';
        }
    }

    /**
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string,model?:string}> $reports
     *
     * @return array<string, int>
     */
    private function parallelActiveTurns(array $reports): array
    {
        $activeTurns = [];
        foreach ($reports as $agentRunId => $report) {
            $state = $this->childRunStore->get($agentRunId);
            if (null !== $state) {
                $activeTurns[$agentRunId] = $state->turnNo;
            }
        }

        return $activeTurns;
    }

    /**
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string}> $reports
     */
    private function resolveParallelAggregateStatus(array $reports): string
    {
        $hasFailed = false;
        $hasCancelled = false;

        foreach ($reports as $report) {
            if (!$report['terminal'] || null === $report['status']) {
                continue;
            }

            if (AgentArtifactStatusEnum::Failed === $report['status']) {
                $hasFailed = true;
            }

            if (AgentArtifactStatusEnum::Cancelled === $report['status']) {
                $hasCancelled = true;
            }
        }

        if ($hasFailed) {
            return 'failed';
        }

        if ($hasCancelled) {
            return 'cancelled';
        }

        return 'completed';
    }

    private function nowMicros(): int
    {
        $instant = $this->clock->now();
        $seconds = (int) $instant->format('U');
        $micro = (int) $instant->format('u');

        return ($seconds * 1_000_000) + $micro;
    }

    private function sleepPollInterval(): void
    {
        $this->clock->sleep(self::DEFAULT_POLL_MICROS / 1_000_000);
    }
}
