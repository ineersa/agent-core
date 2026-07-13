<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Uid\UuidV7;

/**
 * Orchestrates foreground subagent execution (single child or parallel tasks).
 *
 * Implements the full lifecycle:
 *  1. Resolve/enforce agent definition + depth guard + tool policy.
 *  2. Create parent-scoped artifact entry.
 *  3. Build child prompt and messages.
 *  4. Start child run via AgentRunnerInterface.
 *  5. Poll child RunState until terminal, timeout, or cancellation.
 *  6. Finalize registry, handoff, and return result text.
 *
 * Foreground subagents launch as interactive-capable child runs (session.interactive=true).
 * WaitingHuman is supported: parent progress emits waiting_human until the child resumes or is cancelled.
 */
final class SubagentExecutionService
{
    private const int DEFAULT_POLL_MICROS = 250_000;

    public function __construct(
        private readonly AgentDefinitionCatalog $catalog,
        private readonly AgentDepthGuard $depthGuard,
        private readonly AgentToolPolicyResolver $policyResolver,
        private readonly AgentPromptBuilder $promptBuilder,
        private readonly SkillsContextBuilder $skillsContextBuilder,
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly AgentRunnerInterface $agentRunner,
        private readonly RunStoreInterface $runStore,
        private readonly RunStoreInterface $parentRunStore,
        private readonly EventStoreInterface $eventStore,
        private readonly CommittedRunEventAppender $committedRunEventAppender,
        private readonly SubagentRunMetadataReader $metadataReader,
        private readonly AgentChildRunDirectory $childRunDirectory,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly LoggerInterface $logger,
        private readonly AgentsConfig $agentsConfig,
        private readonly SubagentProgressSnapshotBuilder $progressSnapshotBuilder,
        private readonly SubagentChildProgressSummaryBuilder $childProgressSummaryBuilder,
        private readonly AppConfig $appConfig,
        private readonly AgentsContextBuilder $agentsContextBuilder,
        private readonly ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    /**
     * Execute a single foreground subagent run.
     *
     * @param string $parentRunId parent session run ID (required for artifact scoping)
     * @param string $agentName   agent definition name to resolve
     * @param string $task        the task text for the child agent
     *
     * @return string the final handoff/result text
     *
     * @throws ToolCallException on validation, depth, or definition errors
     */
    public function execute(
        string $parentRunId,
        string $agentName,
        string $task,
    ): string {
        // 1. Resolve and validate agent definition.
        try {
            $definition = $this->catalog->requireEnabled($agentName);
        } catch (\RuntimeException $e) {
            throw new ToolCallException(\sprintf('Agent "%s" is not available: %s', $agentName, $e->getMessage()), retryable: false);
        }

        if (!$definition->foregroundAllowed) {
            throw new ToolCallException(\sprintf('Agent "%s" does not allow foreground execution.', $agentName), retryable: false);
        }

        // 2. Defense-in-depth: no nested subagents in v1.
        $parentIsAgentChild = $this->metadataReader->isAgentChild($parentRunId);
        $blockReason = $this->depthGuard->checkLaunchAllowed($parentIsAgentChild);
        if (null !== $blockReason) {
            throw new ToolCallException($blockReason, retryable: false);
        }

        // 3. Resolve tool/MCP policy.
        $allowSubagentLaunch = $this->childMayLaunchSubagents($definition);
        $policy = $this->policyResolver->resolve($definition, $parentRunId, $allowSubagentLaunch);
        $allowedTools = $policy['tools'];

        // 4. Create artifact ID and child run ID (RFC 4122).
        $artifactId = 'agent_'.bin2hex(random_bytes(8));
        $agentRunId = UuidV7::v7()->toRfc4122();

        // 5. Create artifact entry in Pending.
        $entry = $this->artifactRegistry->create(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            agentRunId: $agentRunId,
            agentName: $agentName,
            kind: AgentArtifactKindEnum::Subagent,
        );

        // Pre-populate the locator so routers find the child store immediately.
        $this->childRunDirectory->register($entry);

        // 6. Resolve inherited project/AGENTS/skills context for the child.
        $launchContext = $this->resolveChildLaunchContext($parentRunId, $definition, $allowedTools);

        // 7. Build prompt and messages.
        $prompt = $this->promptBuilder->build(
            definition: $definition,
            task: $task,
            artifactId: $artifactId,
            allowedTools: $allowedTools,
            agentsMd: $launchContext['agentsMd'],
            skillsContext: $launchContext['skillsContext'],
            agentsDefinitionsContext: $launchContext['agentsDefinitionsContext'],
        );

        // 8. Build child metadata with policy and artifact paths.
        $childMetadata = $this->buildChildRunMetadata(
            parentRunId: $parentRunId,
            agentName: $agentName,
            artifactId: $artifactId,
            model: $definition->model,
            reasoning: $definition->thinking,
            allowedTools: $allowedTools,
            mcp: $policy['mcp'],
        );

        // 9. Start child run (AgentRunner generates the runId if null).
        $this->agentRunner->start(new StartRunInput(
            systemPrompt: $prompt['systemPrompt'],
            messages: $prompt['messages'],
            runId: $agentRunId,
            metadata: $childMetadata,
        ));

        // 10. Mark Running in the registry.
        $startedAt = new \DateTimeImmutable();
        $this->artifactRegistry->update(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Running,
            startedAt: $startedAt,
        );

        $progressStartedMicros = $this->nowMicros();

        // 11. Poll child until terminal, timeout, or cancellation.
        $timeoutSeconds = $this->timeoutSeconds();
        $deadline = $this->nowMicros() + $timeoutSeconds * 1_000_000;
        $context = $this->contextAccessor->current();
        $cancelToken = $context?->cancellationToken();

        // Monotonically increasing event seq for progress updates,
        // initialised from max(parent state lastSeq, max parent event seq) + 1.
        $progressSeq = $this->resolveNextProgressSeq($parentRunId);
        $lastSingleProgressSignature = null;

        while (true) {
            // Check parent cancellation.
            if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                $this->agentRunner->cancel($agentRunId, 'Parent run cancelled subagent tool.');
                $cancelState = $this->runStore->get($agentRunId);
                $this->finalize(
                    parentRunId: $parentRunId,
                    artifactId: $artifactId,
                    status: AgentArtifactStatusEnum::Cancelled,
                    summary: 'Cancelled by parent run.',
                    agentName: $agentName,
                    agentRunId: $agentRunId,
                    childState: $cancelState,
                );

                if (null !== $cancelState) {
                    $this->emitTerminalProgressUpdate(
                        parentRunId: $parentRunId,
                        agentRunId: $agentRunId,
                        agentName: $agentName,
                        artifactId: $artifactId,
                        taskSummary: $task,
                        definitionModel: $definition->model,
                        state: $cancelState,
                        terminalStatus: 'cancelled',
                        seq: $progressSeq,
                        progressStartedMicros: $progressStartedMicros,
                    );
                    $this->advanceParentSequence($parentRunId, $progressSeq);
                }

                throw new ToolCallException($this->formatParentCancelledSingleMessage($agentName, $artifactId), retryable: false);
            }

            // Check timeout.
            if ($this->nowMicros() > $deadline) {
                $this->agentRunner->cancel($agentRunId, 'Subagent timed out.');
                $timeoutState = $this->runStore->get($agentRunId);
                if (null !== $timeoutState) {
                    $this->emitTerminalProgressUpdate(
                        parentRunId: $parentRunId,
                        agentRunId: $agentRunId,
                        agentName: $agentName,
                        artifactId: $artifactId,
                        taskSummary: $task,
                        definitionModel: $definition->model,
                        state: $timeoutState,
                        terminalStatus: 'failed',
                        seq: $progressSeq,
                        progressStartedMicros: $progressStartedMicros,
                    );
                    $this->advanceParentSequence($parentRunId, $progressSeq);
                    ++$progressSeq;
                }
                $this->finalize(
                    parentRunId: $parentRunId,
                    artifactId: $artifactId,
                    status: AgentArtifactStatusEnum::Failed,
                    failureReason: 'Child run timed out.',
                    summary: 'Timed out after '.$timeoutSeconds.'s.',
                );

                return \sprintf("Subagent %s timed out after %d seconds. Task: %s\nArtifact: %s",
                    $agentName, $timeoutSeconds, $task, $artifactId);
            }

            $state = $this->runStore->get($agentRunId);
            if (null === $state) {
                $this->sleepPollInterval();
                continue;
            }

            $status = $state->status;

            if (RunStatus::Running === $status || RunStatus::Queued === $status || RunStatus::Compacting === $status) {
                $entry = $this->artifactRegistry->get($parentRunId, $artifactId);
                if (null !== $entry && AgentArtifactStatusEnum::NeedsClarification === $entry->status) {
                    $this->artifactRegistry->update(
                        parentRunId: $parentRunId,
                        artifactId: $artifactId,
                        status: AgentArtifactStatusEnum::Running,
                    );
                }

                $signature = $this->singleProgressSignature(
                    $parentRunId,
                    $agentRunId,
                    $artifactId,
                    $state,
                    $definition->model,
                );
                if (null === $lastSingleProgressSignature || $signature !== $lastSingleProgressSignature) {
                    $this->emitProgressUpdate(
                        parentRunId: $parentRunId,
                        agentRunId: $agentRunId,
                        agentName: $agentName,
                        artifactId: $artifactId,
                        taskSummary: $task,
                        definitionModel: $definition->model,
                        state: $state,
                        seq: $progressSeq,
                        progressStartedMicros: $progressStartedMicros,
                    );
                    $this->advanceParentSequence($parentRunId, $progressSeq);
                    ++$progressSeq;
                    $lastSingleProgressSignature = $signature;
                }

                $this->sleepPollInterval();
                continue;
            }

            if (RunStatus::WaitingHuman === $status) {
                $this->artifactRegistry->update(
                    parentRunId: $parentRunId,
                    artifactId: $artifactId,
                    status: AgentArtifactStatusEnum::NeedsClarification,
                );
                $signature = $this->singleProgressSignature(
                    $parentRunId,
                    $agentRunId,
                    $artifactId,
                    $state,
                    $definition->model,
                );
                if (null === $lastSingleProgressSignature || $signature !== $lastSingleProgressSignature) {
                    $this->emitProgressUpdate(
                        parentRunId: $parentRunId,
                        agentRunId: $agentRunId,
                        agentName: $agentName,
                        artifactId: $artifactId,
                        taskSummary: $task,
                        definitionModel: $definition->model,
                        state: $state,
                        seq: $progressSeq,
                        progressStartedMicros: $progressStartedMicros,
                        progressStatus: 'waiting_human',
                    );
                    $this->advanceParentSequence($parentRunId, $progressSeq);
                    ++$progressSeq;
                    $lastSingleProgressSignature = $signature;
                }

                $this->sleepPollInterval();
                continue;
            }

            // Terminal states only — emit final structured snapshot before returning.
            $terminalStatus = $this->mapChildTerminalProgressStatus($status);
            $this->emitTerminalProgressUpdate(
                parentRunId: $parentRunId,
                agentRunId: $agentRunId,
                agentName: $agentName,
                artifactId: $artifactId,
                taskSummary: $task,
                definitionModel: $definition->model,
                state: $state,
                terminalStatus: $terminalStatus,
                seq: $progressSeq,
                progressStartedMicros: $progressStartedMicros,
            );
            $this->advanceParentSequence($parentRunId, $progressSeq);
            ++$progressSeq;

            return match ($status) {
                RunStatus::Completed => $this->handleCompleted(
                    $parentRunId, $artifactId, $agentName, $state,
                ),
                RunStatus::Failed => $this->handleFailed(
                    $parentRunId, $artifactId, $agentName, $state,
                ),
                RunStatus::Cancelled, RunStatus::Cancelling => $this->handleCancelled(
                    $parentRunId, $artifactId, $agentName, $state,
                ),
            };
        }
    }

    /**
     * Execute multiple foreground subagents in parallel (one tool call).
     *
     * @param list<SubagentTaskDTO> $tasks
     *
     * @throws ToolCallException when validation fails, any child fails, or parent cancels
     */
    public function executeParallel(string $parentRunId, array $tasks): string
    {
        // Defense-in-depth: SubagentTool enforces the same cap before calling this method (LLM-visible fail-fast).
        $maxAgents = $this->agentsConfig->maxAgents;
        $taskCount = \count($tasks);
        if ($taskCount > $maxAgents) {
            throw new ToolCallException(\sprintf('Parallel subagent execution supports at most %d agents per tool call, but %d tasks were requested.', $maxAgents, $taskCount), retryable: false, hint: \sprintf('Split the work into multiple subagent calls with at most %d tasks each.', $maxAgents));
        }

        $parentIsAgentChild = $this->metadataReader->isAgentChild($parentRunId);
        $blockReason = $this->depthGuard->checkLaunchAllowed($parentIsAgentChild);
        if (null !== $blockReason) {
            throw new ToolCallException($blockReason, retryable: false);
        }

        /** @var list<array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,definition:AgentDefinitionDTO}> $launches */
        $launches = [];
        foreach ($tasks as $index => $taskDto) {
            $agentName = $taskDto->trimmedAgent();
            $taskText = $taskDto->trimmedTask();

            try {
                $definition = $this->catalog->requireEnabled($agentName);
            } catch (\RuntimeException $e) {
                throw new ToolCallException(\sprintf('Agent "%s" is not available: %s', $agentName, $e->getMessage()), retryable: false);
            }

            if (!$definition->foregroundAllowed) {
                throw new ToolCallException(\sprintf('Agent "%s" does not allow foreground execution.', $agentName), retryable: false);
            }

            if (!$definition->parallelAllowed) {
                throw new ToolCallException(\sprintf('Agent "%s" does not allow parallel execution. Set parallelAllowed: true in the agent definition or use single subagent mode.', $agentName), retryable: false);
            }

            $launches[] = [
                'index' => $index + 1,
                'agentName' => $agentName,
                'task' => $taskText,
                'artifactId' => 'agent_'.bin2hex(random_bytes(8)),
                'agentRunId' => UuidV7::v7()->toRfc4122(),
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

                $allowSubagentLaunch = $this->childMayLaunchSubagents($launch['definition']);
                $policy = $this->policyResolver->resolve($launch['definition'], $parentRunId, $allowSubagentLaunch);
                $allowedTools = $policy['tools'];

                $launchContext = $this->resolveChildLaunchContext($parentRunId, $launch['definition'], $allowedTools);

                $prompt = $this->promptBuilder->build(
                    definition: $launch['definition'],
                    task: $launch['task'],
                    artifactId: $launch['artifactId'],
                    allowedTools: $allowedTools,
                    agentsMd: $launchContext['agentsMd'],
                    skillsContext: $launchContext['skillsContext'],
                    agentsDefinitionsContext: $launchContext['agentsDefinitionsContext'],
                );

                $childMetadata = $this->buildChildRunMetadata(
                    parentRunId: $parentRunId,
                    agentName: $launch['agentName'],
                    artifactId: $launch['artifactId'],
                    model: $launch['definition']->model,
                    reasoning: $launch['definition']->thinking,
                    allowedTools: $allowedTools,
                    mcp: $policy['mcp'],
                );

                $this->agentRunner->start(new StartRunInput(
                    systemPrompt: $prompt['systemPrompt'],
                    messages: $prompt['messages'],
                    runId: $launch['agentRunId'],
                    metadata: $childMetadata,
                ));

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

        $timeoutSeconds = $this->timeoutSeconds();
        $deadline = $this->nowMicros() + $timeoutSeconds * 1_000_000;
        $context = $this->contextAccessor->current();
        $cancelToken = $context?->cancellationToken();
        $progressSeq = $this->resolveNextProgressSeq($parentRunId);
        $lastProgressSignature = null;
        $parallelProgressStartedMicros = $this->nowMicros();

        while ($this->hasActiveParallelChildren($reports)) {
            if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                foreach ($reports as $agentRunId => $report) {
                    if ($report['terminal']) {
                        continue;
                    }
                    $this->agentRunner->cancel($agentRunId, 'Parent run cancelled parallel subagent tool.');
                    $cancelChildState = $this->runStore->get($agentRunId);
                    $this->finalize(
                        parentRunId: $parentRunId,
                        artifactId: $report['artifactId'],
                        status: AgentArtifactStatusEnum::Cancelled,
                        summary: 'Cancelled by parent run.',
                        agentName: $report['agentName'],
                        agentRunId: $agentRunId,
                        childState: $cancelChildState,
                    );
                    $reports[$agentRunId]['terminal'] = true;
                    $reports[$agentRunId]['status'] = AgentArtifactStatusEnum::Cancelled;
                    $reports[$agentRunId]['message'] = 'Cancelled by parent run.';
                }

                $this->emitParallelProgressUpdate(
                    $parentRunId,
                    $reports,
                    $this->parallelActiveTurns($reports),
                    $progressSeq,
                    $parallelProgressStartedMicros,
                    aggregateStatus: 'cancelled',
                );
                $this->advanceParentSequence($parentRunId, $progressSeq);

                throw new ToolCallException("Parallel subagent tool cancelled by parent run.\n\n".$this->formatParallelReport($reports), retryable: false);
            }

            if ($this->nowMicros() > $deadline) {
                foreach ($reports as $agentRunId => $report) {
                    if ($report['terminal']) {
                        continue;
                    }
                    $this->agentRunner->cancel($agentRunId, 'Parallel subagent timed out.');
                    $this->finalize(
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

                $state = $this->runStore->get($agentRunId);
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
                        $summary = $this->extractLastMessage($state);
                        $this->finalize(
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
                        $this->finalize(
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
                        $this->finalize(
                            parentRunId: $parentRunId,
                            artifactId: $report['artifactId'],
                            status: AgentArtifactStatusEnum::Cancelled,
                            summary: 'Child run was cancelled.',
                            agentName: $report['agentName'],
                            agentRunId: $agentRunId,
                            childState: $state,
                        );
                        $reports[$agentRunId]['terminal'] = true;
                        $reports[$agentRunId]['status'] = AgentArtifactStatusEnum::Cancelled;
                        $reports[$agentRunId]['message'] = 'Child run was cancelled.';
                    })(),
                };
            }

            $signature = $this->parallelProgressSignature($parentRunId, $reports, $activeTurns);
            if (null === $lastProgressSignature || $signature !== $lastProgressSignature) {
                $this->emitParallelProgressUpdate($parentRunId, $reports, $activeTurns, $progressSeq, $parallelProgressStartedMicros);
                $this->advanceParentSequence($parentRunId, $progressSeq);
                ++$progressSeq;
                $lastProgressSignature = $signature;
            }

            $this->sleepPollInterval();
        }

        $this->emitParallelProgressUpdate(
            $parentRunId,
            $reports,
            $this->parallelActiveTurns($reports),
            $progressSeq,
            $parallelProgressStartedMicros,
            aggregateStatus: $this->resolveParallelAggregateStatus($reports),
        );
        $this->advanceParentSequence($parentRunId, $progressSeq);
        ++$progressSeq;

        $failed = array_filter($reports, static fn (array $r): bool => AgentArtifactStatusEnum::Completed !== $r['status']);

        if ([] !== $failed) {
            throw new ToolCallException('Parallel subagent execution failed for one or more children.'."\n\n".$this->formatParallelReport($reports), retryable: false);
        }

        return $this->formatParallelSuccess($reports);
    }

    /**
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string}> $reports
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
                $this->finalize(
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
            $this->finalize(
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
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string}> $reports
     * @param array<string, int>                                                                                                                                            $activeTurns
     */
    private function parallelProgressSignature(string $parentRunId, array $reports, array $activeTurns): string
    {
        $enrichmentByRun = $this->buildParallelEnrichmentByRun($parentRunId, $reports);
        $parts = [];
        foreach ($reports as $agentRunId => $report) {
            if ($report['terminal']) {
                $parts[] = $agentRunId.':terminal:'.(null !== $report['status'] ? $report['status']->value : 'unknown');

                continue;
            }

            if (AgentArtifactStatusEnum::NeedsClarification === $report['status']) {
                $parts[] = $agentRunId.':waiting_human:'.(string) ($activeTurns[$agentRunId] ?? 0);

                continue;
            }

            $enrichment = $enrichmentByRun[$agentRunId] ?? null;
            if (null === $enrichment) {
                $parts[] = $agentRunId.':active:'.($activeTurns[$agentRunId] ?? 0);

                continue;
            }

            $parts[] = implode(':', [
                $agentRunId,
                'active',
                (string) ($activeTurns[$agentRunId] ?? 0),
                (string) $enrichment->toolCount,
                (string) $enrichment->totalTokens,
                (string) $enrichment->inputTokens,
                (string) $enrichment->outputTokens,
                $enrichment->activeToolLine ?? '',
                implode(',', $enrichment->recentTools),
                $enrichment->assistantExcerpt ?? '',
            ]);
        }

        sort($parts);

        return implode('|', $parts);
    }

    /**
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string}> $reports
     *
     * @return array<string, SubagentChildProgressSummary>
     */
    private function buildParallelEnrichmentByRun(string $parentRunId, array $reports): array
    {
        $enrichmentByRun = [];
        foreach ($reports as $agentRunId => $report) {
            $state = $this->runStore->get($agentRunId);
            if (null === $state) {
                continue;
            }

            $model = \is_string($report['model'] ?? null) ? $report['model'] : null;
            $enrichmentByRun[$agentRunId] = $this->childProgressSummaryBuilder->summarize(
                $parentRunId,
                $agentRunId,
                $report['artifactId'],
                $state,
                $model,
            );
        }

        return $enrichmentByRun;
    }

    /**
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string}> $reports
     * @param array<string, int>                                                                                                                                            $activeTurns
     */
    private function emitParallelProgressUpdate(
        string $parentRunId,
        array $reports,
        array $activeTurns,
        int $seq,
        int $progressStartedMicros,
        string $aggregateStatus = 'running',
    ): void {
        $context = $this->contextAccessor->current();
        if (null === $context) {
            return;
        }

        $elapsedMs = $this->elapsedMsSince($progressStartedMicros);
        $enrichmentByRun = $this->buildParallelEnrichmentByRun($parentRunId, $reports);

        $progress = $this->progressSnapshotBuilder->parallelSnapshot(
            $reports,
            $activeTurns,
            $elapsedMs,
            $enrichmentByRun,
            $aggregateStatus,
        );
        $event = new RunEvent(
            runId: $parentRunId,
            seq: $seq,
            turnNo: $context->turnNo(),
            type: RunEventTypeEnum::ToolExecutionUpdate->value,
            payload: [
                'tool_call_id' => $context->toolCallId(),
                'tool_name' => $context->toolName(),
                'delta' => '',
                'subagent_progress' => $progress,
                'order_index' => $context->orderIndex(),
            ],
        );

        $this->committedRunEventAppender->append($event);
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

    // ── Private helpers ──────────────────────────────────────────────

    /**
     * Finalize artifact registry entry and write handoff.
     */
    private function finalize(
        string $parentRunId,
        string $artifactId,
        AgentArtifactStatusEnum $status,
        ?string $summary = null,
        ?string $failureReason = null,
        ?string $needsClarification = null,
        ?string $agentName = null,
        ?string $agentRunId = null,
        ?RunState $childState = null,
    ): void {
        $completedAt = new \DateTimeImmutable();

        $this->artifactRegistry->update(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: $status,
            completedAt: $completedAt,
            summary: $summary,
            failureReason: $failureReason,
            needsClarification: $needsClarification,
        );

        // Write handoff.md for every terminal path.
        $handoff = $this->buildHandoffMarkdown(
            status: $status,
            summary: $summary,
            failureReason: $failureReason,
            needsClarification: $needsClarification,
            artifactId: $artifactId,
            agentName: $agentName,
            agentRunId: $agentRunId,
            childState: $childState,
        );

        $this->artifactRegistry->writeHandoff($parentRunId, $artifactId, $handoff);
    }

    /**
     * Handle Completed child run — finalize and return handoff.
     */
    private function handleCompleted(
        string $parentRunId,
        string $artifactId,
        string $agentName,
        RunState $state,
    ): string {
        $finalMessages = $this->extractLastMessage($state);
        $this->finalize(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Completed,
            summary: $finalMessages,
        );

        return \sprintf(
            "Subagent %s completed.\nArtifact: %s\n\nFull handoff is included below (agent_retrieve is optional for single-mode success; use it only for metadata/history/debug or if you need to re-read this artifact).\n\n%s",
            $agentName,
            $artifactId,
            $finalMessages,
        );
    }

    /**
     * Handle Failed child run — finalize and return error.
     */
    private function handleFailed(
        string $parentRunId,
        string $artifactId,
        string $agentName,
        RunState $state,
    ): string {
        $errorMsg = $state->errorMessage ?? 'Run failed without error message.';
        $this->finalize(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Failed,
            failureReason: $errorMsg,
            summary: $errorMsg,
        );

        return \sprintf("Subagent %s failed: %s\nArtifact: %s",
            $agentName, $errorMsg, $artifactId);
    }

    /**
     * Handle Cancelled/Cancelling child run — finalize and return message.
     */
    private function handleCancelled(
        string $parentRunId,
        string $artifactId,
        string $agentName,
        RunState $state,
    ): string {
        $this->logger->info('subagent_execution.cancelled', [
            'component' => 'agent.execution',
            'event_type' => 'subagent_execution.cancelled',
            'agent_name' => $agentName,
            'artifact_id' => $artifactId,
        ]);

        $this->finalize(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Cancelled,
            summary: 'Child run was cancelled.',
            agentName: $agentName,
            agentRunId: $state->runId,
            childState: $state,
        );

        return $this->formatChildCancelledMessage($agentName, $artifactId);
    }

    /**
     * Extract the last assistant message text from RunState.
     */
    private function extractLastMessage(RunState $state): string
    {
        $lastText = '';
        foreach (array_reverse($state->messages) as $message) {
            if ('assistant' !== $message->role) {
                continue;
            }
            foreach ($message->content as $block) {
                if ('text' === ($block['type'] ?? '') && isset($block['text'])) {
                    $lastText = (string) $block['text'];
                    break 2;
                }
            }
        }

        if ('' === $lastText) {
            $lastText = \sprintf('%s with status %s.', $state->status->name, $state->status->value);
        }

        return $lastText;
    }

    /**
     * Build handoff markdown content for the artifact's handoff.md.
     */
    private function buildHandoffMarkdown(
        AgentArtifactStatusEnum $status,
        ?string $summary,
        ?string $failureReason,
        ?string $needsClarification,
        ?string $artifactId = null,
        ?string $agentName = null,
        ?string $agentRunId = null,
        ?RunState $childState = null,
    ): string {
        if (AgentArtifactStatusEnum::Cancelled === $status) {
            return $this->buildCancelledHandoffMarkdown(
                artifactId: $artifactId,
                agentName: $agentName,
                agentRunId: $agentRunId,
                summary: $summary,
                childState: $childState,
            );
        }

        $lines = [
            '# Subagent handoff',
            '',
            'Status: '.$status->value,
        ];

        if (null !== $summary) {
            $lines[] = '';
            $lines[] = '## Result';
            $lines[] = '';
            $lines[] = $summary;
        }

        if (null !== $failureReason) {
            $lines[] = '';
            $lines[] = '## Failure reason';
            $lines[] = '';
            $lines[] = $failureReason;
        }

        if (null !== $needsClarification) {
            $lines[] = '';
            $lines[] = '## Needs clarification';
            $lines[] = '';
            $lines[] = $needsClarification;
        }

        return implode("\n", $lines)."\n";
    }

    private function buildCancelledHandoffMarkdown(
        ?string $artifactId,
        ?string $agentName,
        ?string $agentRunId,
        ?string $summary,
        ?RunState $childState,
    ): string {
        $template = <<<'MD'
# Subagent handoff

Status: cancelled
{artifact_line}{agent_line}{agent_run_line}
## Cancellation

{summary_text}
{partial_context_block}{retrieval_hint}
MD;

        $summaryText = null !== $summary ? trim($summary) : '';
        $replacements = [
            '{artifact_line}' => (null !== $artifactId && '' !== $artifactId) ? 'Artifact: {artifact_id}'.'
' : '',
            '{agent_line}' => (null !== $agentName && '' !== $agentName) ? 'Agent: {agent_name}'.'
' : '',
            '{agent_run_line}' => (null !== $agentRunId && '' !== $agentRunId) ? 'Agent run: {agent_run_id}'.'
' : '',
            '{summary_text}' => '' !== $summaryText ? $summaryText : 'Child run was cancelled.',
            '{partial_context_block}' => '',
            '{retrieval_hint}' => '',
        ];

        if (null !== $childState) {
            $lastActivity = $this->summarizeLastKnownActivity($childState);
            $excerpt = $this->extractLastMessage($childState);
            $includeExcerpt = '' !== trim($excerpt) && !str_starts_with($excerpt, $childState->status->name);
            $partial = <<<'MD'

## Partial context

- turn_no: {turn_no}
- last_seq: {last_seq}
- message_count: {message_count}
- pending_tool_calls: {pending_tool_calls}
{last_activity_line}{assistant_excerpt_block}
MD;
            $partialReplacements = [
                '{turn_no}' => (string) $childState->turnNo,
                '{last_seq}' => (string) $childState->lastSeq,
                '{message_count}' => (string) \count($childState->messages),
                '{pending_tool_calls}' => (string) \count($childState->pendingToolCalls),
                '{last_activity_line}' => '' !== $lastActivity ? '- last_known_activity: {last_activity}'.'
' : '',
                '{assistant_excerpt_block}' => $includeExcerpt ? '
## Last assistant excerpt'.'

{assistant_excerpt}'.'
' : '',
            ];
            $partial = strtr($partial, $partialReplacements);
            if ('' !== $lastActivity) {
                $partial = strtr($partial, ['{last_activity}' => $lastActivity]);
            }
            if ($includeExcerpt) {
                $partial = strtr($partial, ['{assistant_excerpt}' => $this->truncateHandoffText($excerpt, 800)]);
            }
            $replacements['{partial_context_block}'] = $partial;
            $replacements['{retrieval_hint}'] = '
Use agent_retrieve (metadata/events/history) for more child details.'.'
';
        }

        $markdown = strtr($template, $replacements);
        $valueMap = [
            '{artifact_id}' => $artifactId ?? '',
            '{agent_name}' => $agentName ?? '',
            '{agent_run_id}' => $agentRunId ?? '',
        ];

        return strtr($markdown, $valueMap);
    }

    private function formatParentCancelledSingleMessage(string $agentName, string $artifactId): string
    {
        $template = <<<'TXT'
{headline}
Artifact: {artifact_id}
Status: cancelled
Use agent_retrieve (metadata/events/history) for partial child details.
TXT;

        return strtr($template, [
            '{headline}' => \sprintf('Subagent %s cancelled by parent run.', $agentName),
            '{artifact_id}' => $artifactId,
        ]);
    }

    private function formatChildCancelledMessage(string $agentName, string $artifactId): string
    {
        $template = <<<'TXT'
{headline}
Artifact: {artifact_id}
Status: cancelled
Use agent_retrieve (metadata/events/history) for partial child details.
TXT;

        return strtr($template, [
            '{headline}' => \sprintf('Subagent %s was cancelled.', $agentName),
            '{artifact_id}' => $artifactId,
        ]);
    }

    private function summarizeLastKnownActivity(RunState $state): string
    {
        if ([] !== $state->pendingToolCalls) {
            $pendingIds = array_keys($state->pendingToolCalls);
            $firstId = '' !== ($pendingIds[0] ?? '') ? (string) $pendingIds[0] : 'tool_call';

            return 'pending tool_call: '.$this->truncateHandoffText($firstId, 120);
        }

        foreach (array_reverse($state->messages) as $message) {
            if ('assistant' === $message->role) {
                return 'assistant message at turn '.$state->turnNo;
            }
        }

        return 'run status '.$state->status->value;
    }

    private function truncateHandoffText(string $text, int $maxLen): string
    {
        $trimmed = trim($text);
        if (\strlen($trimmed) <= $maxLen) {
            return $trimmed;
        }

        return substr($trimmed, 0, $maxLen - 3).'...';
    }

    private function emitTerminalProgressUpdate(
        string $parentRunId,
        string $agentRunId,
        string $agentName,
        string $artifactId,
        string $taskSummary,
        ?string $definitionModel,
        RunState $state,
        string $terminalStatus,
        int $seq,
        int $progressStartedMicros,
    ): void {
        $context = $this->contextAccessor->current();
        if (null === $context) {
            return;
        }

        $elapsedMs = $this->elapsedMsSince($progressStartedMicros);
        $enrichment = $this->childProgressSummaryBuilder->summarize(
            $parentRunId,
            $agentRunId,
            $artifactId,
            $state,
            $definitionModel,
        );
        $progress = $this->progressSnapshotBuilder->singleTerminal(
            $terminalStatus,
            $agentName,
            $artifactId,
            $agentRunId,
            $taskSummary,
            $state,
            $elapsedMs,
            $enrichment,
        );
        $this->appendSubagentProgressEvent($parentRunId, $context, $seq, $progress);
    }

    /**
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string}> $reports
     *
     * @return array<string, int>
     */
    private function parallelActiveTurns(array $reports): array
    {
        $activeTurns = [];
        foreach ($reports as $agentRunId => $report) {
            $state = $this->runStore->get($agentRunId);
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

    private function mapChildTerminalProgressStatus(RunStatus $status): string
    {
        return match ($status) {
            RunStatus::Completed => 'completed',
            RunStatus::Failed => 'failed',
            RunStatus::Cancelled, RunStatus::Cancelling => 'cancelled',
            default => 'done',
        };
    }

    private function singleProgressSignature(
        string $parentRunId,
        string $agentRunId,
        string $artifactId,
        RunState $state,
        ?string $definitionModel,
    ): string {
        $enrichment = $this->childProgressSummaryBuilder->summarize(
            $parentRunId,
            $agentRunId,
            $artifactId,
            $state,
            $definitionModel,
        );

        return implode('|', [
            (string) $state->turnNo,
            $state->status->value,
            (string) $enrichment->toolCount,
            (string) $enrichment->totalTokens,
            (string) $enrichment->inputTokens,
            (string) $enrichment->outputTokens,
            $enrichment->activeToolLine ?? '',
            implode(',', $enrichment->recentTools),
            $enrichment->assistantExcerpt ?? '',
        ]);
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function appendSubagentProgressEvent(
        string $parentRunId,
        \Ineersa\AgentCore\Application\Tool\ToolContext $context,
        int $seq,
        array $progress,
    ): void {
        $event = new RunEvent(
            runId: $parentRunId,
            seq: $seq,
            turnNo: $context->turnNo(),
            type: RunEventTypeEnum::ToolExecutionUpdate->value,
            payload: [
                'tool_call_id' => $context->toolCallId(),
                'tool_name' => $context->toolName(),
                'delta' => '',
                'subagent_progress' => $progress,
                'order_index' => $context->orderIndex(),
            ],
        );

        $this->committedRunEventAppender->append($event);
    }

    /**
     * Emit a ToolExecutionUpdate event into the parent run's event stream.
     *
     * Produces a lightweight status line (RunState-based) rather than
     * scanning the full child event log each poll.
     */
    private function emitProgressUpdate(
        string $parentRunId,
        string $agentRunId,
        string $agentName,
        string $artifactId,
        string $taskSummary,
        ?string $definitionModel,
        RunState $state,
        int $seq,
        int $progressStartedMicros,
        string $progressStatus = 'running',
    ): void {
        $context = $this->contextAccessor->current();
        if (null === $context) {
            return;
        }

        $elapsedMs = $this->elapsedMsSince($progressStartedMicros);
        $enrichment = $this->childProgressSummaryBuilder->summarize(
            $parentRunId,
            $agentRunId,
            $artifactId,
            $state,
            $definitionModel,
        );
        $progress = $this->progressSnapshotBuilder->singleRunning(
            $agentName,
            $artifactId,
            $agentRunId,
            $taskSummary,
            $state,
            $elapsedMs,
            $enrichment,
            $progressStatus,
        );
        $this->appendSubagentProgressEvent($parentRunId, $context, $seq, $progress);
    }

    /**
     * Resolve the next sequence number for parent progress events.
     *
     * Uses the greater of parent state lastSeq and the maximum existing
     * parent event seq, so progress events never collide with events
     * appended by other writers or pending commits.
     */
    private function resolveNextProgressSeq(string $parentRunId): int
    {
        $parentState = $this->parentRunStore->get($parentRunId);
        $stateLastSeq = null !== $parentState ? $parentState->lastSeq : 0;

        // Fallback to max event seq in case state is temporarily stale
        // (e.g. mid-commit, or another writer advanced the log without
        // yet persisting the state checkpoint).
        $parentEvents = $this->eventStore->allFor($parentRunId);
        $maxEventSeq = 0;
        foreach ($parentEvents as $event) {
            if ($event->seq > $maxEventSeq) {
                $maxEventSeq = $event->seq;
            }
        }

        return max($stateLastSeq, $maxEventSeq) + 1;
    }

    /**
     * Advance the parent RunState.lastSeq to include the progress event.
     *
     * Uses compareAndSwap in a short retry loop to handle races with
     * other parent state writers.  If CAS fails and the current state
     * already covers our seq, treats it as a non-issue.  Exhausted
     * retries are logged but do not block or re-append — the progress
     * event already exists in the event log and replay will catch up.
     */
    private function advanceParentSequence(string $parentRunId, int $seq): void
    {
        $parentState = $this->parentRunStore->get($parentRunId);
        if (null === $parentState) {
            // Parent state not yet persisted — the first progress event
            // lands while the run is still initialising.  Non-fatal.
            $this->logger->warning('subagent_execution.parent_state_missing_for_seq_advance', [
                'component' => 'agent.execution',
                'event_type' => 'subagent_execution.parent_state_missing_for_seq_advance',
                'parent_run_id' => $parentRunId,
                'target_seq' => $seq,
            ]);

            return;
        }

        $maxAttempts = 3;

        for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
            $nextState = new RunState(
                runId: $parentState->runId,
                status: $parentState->status,
                version: $parentState->version + 1,
                turnNo: $parentState->turnNo,
                lastSeq: $seq,
                isStreaming: $parentState->isStreaming,
                streamingMessage: $parentState->streamingMessage,
                pendingToolCalls: $parentState->pendingToolCalls,
                errorMessage: $parentState->errorMessage,
                messages: $parentState->messages,
                activeStepId: $parentState->activeStepId,
                retryableFailure: $parentState->retryableFailure,
            );

            $oldVersion = $parentState->version;

            if ($this->parentRunStore->compareAndSwap($nextState, $oldVersion)) {
                return;
            }

            // CAS failed — re-read in case another writer moved past us.
            $parentState = $this->parentRunStore->get($parentRunId);
            if (null === $parentState) {
                return;
            }

            if ($parentState->lastSeq >= $seq) {
                // Another writer already advanced past our seq.
                return;
            }
        }

        // Exhausted retries; event is already in the log.
        $this->logger->warning('subagent_execution.progress_seq_cas_failed', [
            'component' => 'agent.execution',
            'event_type' => 'subagent_execution.progress_seq_cas_failed',
            'parent_run_id' => $parentRunId,
            'target_seq' => $seq,
            'attempts' => $maxAttempts,
            'parent_state_last_seq' => $parentState->lastSeq,
        ]);
    }

    /**
     * Build canonical child run metadata including model context window from catalog.
     *
     * @param list<string>         $allowedTools
     * @param array<string, mixed> $mcp
     */
    private function buildChildRunMetadata(
        string $parentRunId,
        string $agentName,
        string $artifactId,
        ?string $model,
        ?string $reasoning,
        array $allowedTools,
        array $mcp,
    ): RunMetadata {
        $contextWindow = $this->resolveContextWindowForModel($model);

        return new RunMetadata(
            session: [
                'kind' => 'agent_child',
                'parent_run_id' => $parentRunId,
                'agent_name' => $agentName,
                'artifact_id' => $artifactId,
                'interactive' => true,
            ],
            model: $model,
            reasoning: $reasoning,
            toolsScope: [
                'allowed_tools' => $allowedTools,
                'mcp' => $mcp,
            ],
            contextWindow: $contextWindow > 0 ? $contextWindow : null,
        );
    }

    private function resolveContextWindowForModel(?string $model): int
    {
        if (null === $model || '' === trim($model)) {
            return 0;
        }

        $catalog = $this->appConfig->catalog;
        if (null === $catalog) {
            return 0;
        }

        $ref = AiModelReference::tryParse($model);
        if (null === $ref) {
            return 0;
        }

        $definition = $catalog->getModel($ref);

        return null !== $definition ? ($definition->contextWindow ?? 0) : 0;
    }

    /**
     * Resolve project/AGENTS/skills and agent-definitions context for a child launch
     * from parent state and agent definition frontmatter flags.
     *
     * @param list<string> $allowedTools final resolved child tool allowlist
     *
     * @return array{agentsMd: string, skillsContext: string, agentsDefinitionsContext: string}
     */
    private function resolveChildLaunchContext(string $parentRunId, AgentDefinitionDTO $definition, array $allowedTools): array
    {
        $inheritProject = $definition->inheritProjectContext;
        $inheritAgents = $definition->inheritAgentsMd;
        $agentsMd = ($inheritProject || $inheritAgents)
            ? $this->extractUserContextBySource($parentRunId, 'agents_context')
            : '';

        $skillsContext = $this->resolveSkillsContextForChild($definition);

        $agentsDefinitionsContext = '';
        if (\in_array('subagent', $allowedTools, true)) {
            $agentsDefinitionsContext = $this->extractUserContextBySource($parentRunId, 'agents_definitions_context');
            if ('' === trim($agentsDefinitionsContext)) {
                $agentsDefinitionsContext = $this->agentsContextBuilder->build();
            }
        }

        return [
            'agentsMd' => $agentsMd,
            'skillsContext' => $skillsContext,
            'agentsDefinitionsContext' => $agentsDefinitionsContext,
        ];
    }

    private function childMayLaunchSubagents(AgentDefinitionDTO $definition): bool
    {
        $tools = $definition->tools;
        if (null === $tools) {
            return false;
        }

        return \in_array('subagent', $tools, true);
    }

    private function resolveSkillsContextForChild(AgentDefinitionDTO $definition): string
    {
        if ([] === $definition->skills) {
            return '';
        }

        return $this->skillsContextBuilder->buildFor($definition->skills);
    }

    /**
     * Extract text from a parent user-context message by metadata source.
     */
    private function extractUserContextBySource(string $parentRunId, string $source): string
    {
        $state = $this->parentRunStore->get($parentRunId);
        if (null === $state) {
            return '';
        }

        foreach ($state->messages as $message) {
            if ('user-context' !== $message->role) {
                continue;
            }
            if ($source !== ($message->metadata['source'] ?? null)) {
                continue;
            }
            foreach ($message->content as $block) {
                if ('text' === ($block['type'] ?? '') && isset($block['text'])) {
                    return (string) $block['text'];
                }
            }
        }

        return '';
    }

    /**
     * Resolve effective timeout from ambient ToolContext or fallback default.
     */
    private function timeoutSeconds(): int
    {
        return $this->agentsConfig->subagentToolTimeoutSeconds;
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

    private function elapsedMsSince(int $startedMicros): int
    {
        return (int) (($this->nowMicros() - $startedMicros) / 1000);
    }
}
