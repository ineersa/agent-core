<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer;
use Ineersa\CodingAgent\Agent\Fork\ForkSessionSnapshotSerializer;
use Ineersa\CodingAgent\Mcp\McpSessionLifecycleDispatcher;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextDiscovery;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextRenderer;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Psr\Log\LoggerInterface;

/**
 * Controller-side fork child start service.
 *
 * Loads a fork snapshot from disk, builds fresh system prompt and user-context
 * messages for the child CWD, composes the full seed message list (fresh prologue
 * + sanitized history + fork task prompt), and starts the agent run.
 *
 * Lives in the controller process where all AppAgent services are available.
 * This is NOT a general-purpose session client — only for fork child bootstrap.
 * Fork finalization (handoff validation, repair, artifact writing) is handled
 * by ForkRunTerminalWatcher, also in the controller process.
 *
 * The normal agent start (non-fork) continues to use InProcessAgentSessionClient
 * directly.  Fork-specific logic is isolated to this service and related
 * controller-side watchers.
 */
final readonly class ForkControllerStartService
{
    public function __construct(
        private AgentRunnerInterface $runner,
        private SystemPromptBuilder $systemPromptBuilder,
        private AgentsContextDiscovery $agentsContextDiscovery,
        private AgentsContextRenderer $agentsContextRenderer,
        private SkillsContextBuilder $skillsContextBuilder,
        private AgentsContextBuilder $agentsContextBuilder,
        private ForkChildMessageComposer $messageComposer,
        private ForkSessionSnapshotSerializer $snapshotSerializer,
        private ?McpSessionLifecycleDispatcher $mcpDispatcher = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Start a fork child run from scalar fork options.
     *
     * Loads the snapshot from fork_snapshot_path, builds fresh child-cwd
     * system + user-context messages, composes them via ForkChildMessageComposer,
     * starts the agent run, and returns a RunHandle.
     *
     * @param array<string, mixed> $forkOptions Scalar fork options (fork_mode,
     *                                          fork_snapshot_path, fork_child_run_id,
     *                                          fork_parent_run_id, fork_artifact_id, …)
     *
     * @return RunHandle The started run handle
     *
     * @throws \RuntimeException When snapshot loading or run start fails
     */
    public function start(array $forkOptions): RunHandle
    {
        $snapshotPath = (string) ($forkOptions['fork_snapshot_path'] ?? '');
        if ('' === $snapshotPath) {
            throw new \RuntimeException('Fork snapshot path is required but was empty.');
        }

        $childRunId = (string) ($forkOptions['fork_child_run_id'] ?? '');
        $parentRunId = (string) ($forkOptions['fork_parent_run_id'] ?? '');
        $artifactId = (string) ($forkOptions['fork_artifact_id'] ?? '');

        // ── 1. Load snapshot ──
        $snapshot = $this->snapshotSerializer->fromFile($snapshotPath);

        // ── 2. Build fresh system prompt for child CWD ──
        $systemPromptText = $this->systemPromptBuilder->build();
        $messages = [];
        if ('' !== $systemPromptText) {
            $messages[] = new AgentMessage(
                role: 'system',
                content: [['type' => 'text', 'text' => $systemPromptText]],
            );
        }

        // ── 3. Build fresh user-context messages for child CWD ──
        // AGENTS.md context (project-level instructions)
        $agentsContext = $this->agentsContextDiscovery->discover();
        if ([] !== $agentsContext) {
            $contextText = $this->agentsContextRenderer->render($agentsContext);
            $messages[] = new AgentMessage(
                role: 'user-context',
                content: [['type' => 'text', 'text' => $contextText]],
                metadata: ['source' => 'agents_context', 'files' => array_column($agentsContext, 'path')],
            );
        }

        // Skills context (available skill instructions)
        $skillsContext = $this->skillsContextBuilder->build();
        if ('' !== $skillsContext) {
            $messages[] = new AgentMessage(
                role: 'user-context',
                content: [['type' => 'text', 'text' => $skillsContext]],
                metadata: ['source' => 'skills_context'],
            );
        }

        // Available agent definitions
        $availableAgentsContext = $this->agentsContextBuilder->build();
        if ('' !== $availableAgentsContext) {
            $messages[] = new AgentMessage(
                role: 'user-context',
                content: [['type' => 'text', 'text' => $availableAgentsContext]],
                metadata: ['source' => 'agents_definitions_context'],
            );
        }

        // ── 4. Compose full message set via ForkChildMessageComposer ──
        // Pass fresh context messages (without the first system message — the
        // composer handles system + combined system prompt internally).
        $input = $this->messageComposer->compose(
            snapshot: $snapshot,
            childRunId: $childRunId,
            freshSystemPrompt: $systemPromptText,
            freshContextMsgs: \array_slice($messages, 1),
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            resolvedModel: $snapshot->resolvedModel,
        );

        // ── 5. Start the agent run ──
        $runId = $this->runner->start($input);

        // ── 6. Dispatch MCP session initialize ──
        // Failure is non-fatal — MCP is optional infrastructure.
        $this->mcpDispatcher?->dispatchInitialize($runId, 'start_run');

        $this->logger?->info('fork.controller.start_service.started', [
            'component' => 'fork.controller_start',
            'event_type' => 'fork.controller.start_service.started',
            'run_id' => $runId,
            'artifact_id' => $artifactId,
            'child_run_id' => $childRunId,
            'parent_run_id' => $parentRunId,
        ]);

        return new RunHandle(runId: $runId, status: 'running');
    }
}
