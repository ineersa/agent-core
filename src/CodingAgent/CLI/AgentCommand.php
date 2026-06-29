<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI;

use Ineersa\CodingAgent\Config\ForkLevelEnum;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Controller\HeadlessController;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use Ineersa\Tui\Application\InteractiveMode;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Unified agent command — TUI (default) or headless JSONL mode.
 *
 * Usage:
 *   agent                              # Interactive TUI mode (in-process transport)
 *   agent --headless                   # JSONL protocol on stdin/stdout
 *   agent --prompt="Do X"              # TUI with initial prompt
 *   agent --resume=<sessionId>         # Resume existing session
 *   agent --transport=process          # Use process-isolated transport in TUI mode
 *
 * Session persistence:
 *   Every TUI session creates a directory under .hatfield/sessions/<session-id>/
 *   containing state.json and events.jsonl. Transcript projection is rebuilt
 *   from events.jsonl on resume.
 */
#[AsCommand(name: 'agent', description: 'Agent session — TUI (default) or headless JSONL runtime')]
final class AgentCommand
{
    public function __construct(
        private InProcessAgentSessionClient $inProcessClient,
        private JsonlProcessAgentSessionClient $processClient,
        private InteractiveMode $interactiveMode,
        private HatfieldSessionStore $sessionStore,
        private SkillsConfig $skillsConfig,
        private PromptTemplatesRuntimeConfig $promptTemplatesConfig,
        private LoggerInterface $logger,
        private readonly ?StartupDatabaseMigrator $startupDatabaseMigrator = null,
        private ?HeadlessController $controller = null,
        private readonly ?ToolRegistryInterface $toolRegistry = null,
    ) {
    }

    /**
     * @param list<string> $promptTemplate
     */
    public function __invoke(
        #[Option(description: 'Run in headless JSONL protocol mode (stdin/stdout)')]
        bool $headless = false,

        #[Option(description: 'Run in controller event-loop mode (stdin/stdout, non-blocking)')]
        bool $controller = false,

        #[Option(description: 'Transport type: "process" (default, spawns --controller) or "in-process" (sync, broken after ASYNC-05)')]
        string $transport = 'process',

        #[Option(description: 'Initial prompt for a new run')]
        string $prompt = '',

        #[Option(description: 'Resume an existing session by ID')]
        string $resume = '',

        #[Option(description: 'Model identifier (e.g. deepseek/deepseek-v4-pro)')]
        string $model = '',

        #[Option(description: 'Reasoning/thinking level: off, minimal, low, medium, high, xhigh')]
        string $reasoning = '',

        #[Option(description: 'Working directory for session/storage resolution (defaults to current CWD)')]
        string $cwd = '',

        #[Option(description: 'Disable auto-discovery of skills (only --skills-path entries are used)')]
        bool $noSkills = false,

        #[Option(description: 'Additional skill search path')]
        array $skillsPath = [],

        #[Option(description: 'Preload a skill by name (repeatable)')]
        array $skills = [],

        #[Option(description: 'Comma-separated allowlist of model-visible tool names (all tools visible when omitted)')]
        string $tools = '',

        #[Option(description: 'Comma-separated denylist of tool names to hide from the model')]
        string $toolsExcluded = '',

        #[Option(description: 'Load a prompt template file or directory (repeatable)')]
        array $promptTemplate = [],

        #[Option(description: 'Disable prompt template auto-discovery and settings-loaded templates; --prompt-template paths still load')]
        bool $noPromptTemplates = false,

        // ── Fork mode options ──
        #[Option(description: 'Run in fork child mode (requires HATFIELD_FORK=1 env var)')]
        bool $fork = false,

        #[Option(description: 'Path to fork snapshot JSON file (requires --fork)')]
        string $snapshot = '',

        #[Option(description: 'Result artifact directory path (requires --fork)')]
        string $resultDir = '',

        #[Option(description: 'Parent session run ID (requires --fork)')]
        string $parentRunId = '',

        #[Option(description: 'Fork artifact ID (requires --fork)')]
        string $forkRunId = '',

        #[Option(description: 'Child agent run ID (requires --fork)')]
        string $childRunId = '',

        #[Option(description: 'Fork task description')]
        string $task = '',

        #[Option(description: 'Fork level: junior, middle, senior')]
        string $level = '',

        // ── End fork options ──

        ?OutputInterface $output = null,
    ): int {
        if (null === $output) {
            throw new \RuntimeException('AgentCommand requires OutputInterface');
        }

        try {
            // Override CWD before any service access when --cwd is provided.
            // This ensures app.cwd reflects the requested directory, not the
            // directory where the cached container was compiled.
            if ('' !== $cwd) {
                if (!is_dir($cwd)) {
                    throw new \RuntimeException(\sprintf('Working directory does not exist: %s', $cwd));
                }
                chdir($cwd);

                // Keep HATFIELD_CWD env var in sync with the resolved CWD so
                // any service that reads it lazily (e.g. via %%env(HATFIELD_CWD)%%)
                // gets the correct value even though Kernel::boot() already ran with
                // the original CWD.
                $_ENV['HATFIELD_CWD'] = $cwd;
                putenv('HATFIELD_CWD='.$cwd);
            }

            // Populate skills config from CLI options before any session starts.
            // SkillDiscovery reads this config lazily on first discover() call.
            $this->skillsConfig->noSkills = $noSkills;
            $this->skillsConfig->skillsPaths = $skillsPath;
            $this->skillsConfig->preloadSkills = $skills;

            // Populate prompt-template runtime config from CLI options.
            // This is read by PromptTemplateLoader when the service first
            // loads templates, and by JsonlProcessAgentSessionClient when
            // spawning a controller child process.
            $this->promptTemplatesConfig->promptTemplatePaths = $promptTemplate;
            $this->promptTemplatesConfig->noPromptTemplates = $noPromptTemplates;

            // Apply tool filtering before any session/client starts so the
            // system prompt and toolbox reflect CLI-specified allowlist/denylist.
            $this->applyToolFilters($tools, $toolsExcluded);

            // In-process transport: hide fork tool from model toolbox.
            // Fork tool cannot cross the JSONL process boundary used by
            // the default process transport, so it must not be model-visible
            // when running in-process.  This is a no-op before FORK-05
            // registers the fork tool (safe check via activeToolNames()).
            if ('in-process' === $transport && null !== $this->toolRegistry) {
                $this->excludeForkToolSafely();
            }

            // Run pending database migrations once on agent startup.
            // StartupDatabaseMigrator is idempotent per process lifetime and
            // safe for concurrent controller+consumer processes.
            // Runs built-in doctrine:migrations:migrate via the MigrateCommand service.
            // Running here ensures migrations complete before any
            // controller/TUI/headless path accesses the DB.
            if (null !== $this->startupDatabaseMigrator) {
                ($this->startupDatabaseMigrator)();
            }

            // Extension loading is handled by ExtensionLoaderSubscriber
            // (fires on ConsoleEvents::COMMAND) which loads extensions in
            // every process including messenger:consume workers.

            // ── Fork mode ──
            // Routes through the normal TUI path with fork-seeded messages.
            // The child starts as a full TUI/normal Hatfield process with
            // CWD applied, tools excluded, and the fork snapshot loaded.
            if ($fork) {
                // Fork mode requires process transport — in-process does not
                // support fork child mode (the fork child is a real tmux process
                // that communicates via the controller JSONL protocol).
                if ('in-process' === $transport) {
                    throw new \RuntimeException('Fork mode is not supported with --transport=in-process. Fork children must use the default process transport (--transport=process).');
                }

                return $this->runForkTui(
                    snapshotPath: $snapshot,
                    resultDir: $resultDir,
                    parentRunId: $parentRunId,
                    forkRunId: $forkRunId,
                    childRunId: $childRunId,
                    level: $level,
                    task: $task,
                    model: $model,
                    reasoning: $reasoning,
                );
            }

            if ($controller) {
                return $this->runController();
            }

            if ($headless) {
                return $this->runHeadless($output);
            }

            return $this->runTui($transport, $prompt, $resume, $model, $reasoning);
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled exception in agent command', [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    private function runTui(string $transport, string $prompt, string $resume, string $model = '', string $reasoning = ''): int
    {
        $client = $this->resolveClient($transport);

        $sessionId = '';
        if ('' !== $resume) {
            $sessionId = $resume;
            if (!$this->sessionStore->exists($sessionId)) {
                throw new \RuntimeException(\sprintf('Session not found: "%s". Use --prompt to start a new session.', $sessionId));
            }
        }

        return $this->interactiveMode->run(
            client: $client,
            request: '' !== $prompt ? new StartRunRequest(
                prompt: $prompt,
                model: '' !== $model ? $model : null,
                reasoning: '' !== $reasoning ? $reasoning : null,
            ) : null,
            sessionId: $sessionId,
        );
    }

    /**
     * Run the agent in fork child mode through the normal TUI path.
     *
     * Validates the fork guard, loads the snapshot, composes fork-seeded
     * messages, and enters InteractiveMode.  After the TUI exits (auto-exit
     * on run completion via ForkAutoExitRegistrar), writes the exit marker
     * to stdout so the completion watcher (FORK-05) can detect it.
     */
    private function runForkTui(
        string $snapshotPath,
        string $resultDir,
        string $parentRunId,
        string $forkRunId,
        string $childRunId,
        string $level = '',
        string $task = '',
        string $model = '',
        string $reasoning = '',
    ): int {
        // ── Validate HATFIELD_FORK guard ──
        $forkGuard = $_SERVER['HATFIELD_FORK'] ?? getenv('HATFIELD_FORK');
        if ('1' !== $forkGuard) {
            throw new \RuntimeException('Fork mode requires HATFIELD_FORK=1 environment variable for defense-in-depth.');
        }

        // ── Validate required options ──
        if ('' === $snapshotPath) {
            throw new \RuntimeException('--snapshot is required in fork mode.');
        }
        if ('' === $resultDir) {
            throw new \RuntimeException('--result-dir is required in fork mode.');
        }
        if ('' === $parentRunId) {
            throw new \RuntimeException('--parent-run-id is required in fork mode.');
        }
        if ('' === $forkRunId) {
            throw new \RuntimeException('--fork-run-id is required in fork mode.');
        }
        if ('' === $childRunId) {
            throw new \RuntimeException('--child-run-id is required in fork mode.');
        }

        // ── Set HATFIELD_FORK env var for runtime visibility ──
        $_ENV['HATFIELD_FORK'] = '1';
        putenv('HATFIELD_FORK=1');

        // ── Safe fork tool exclusion (nested fork guard) ──
        // Exclude the fork tool if registered (FORK-05+), safely no-op
        // before FORK-05 registers it.  Prevents nested fork calls.
        $this->excludeForkToolSafely();

        // ── Validate snapshot path ──
        // The snapshot is loaded and deserialized by ForkControllerStartService
        // in the controller process.  Here we only validate the file exists.
        if (!is_file($snapshotPath)) {
            throw new \RuntimeException(\sprintf('Fork snapshot file not found: %s', $snapshotPath));
        }
        if (!is_readable($snapshotPath)) {
            throw new \RuntimeException(\sprintf('Fork snapshot file not readable: %s', $snapshotPath));
        }

        // ── Ensure result directory exists ──
        if (!is_dir($resultDir)) {
            if (!mkdir($resultDir, 0o755, true) && !is_dir($resultDir)) {
                throw new \RuntimeException(\sprintf('Failed to create result directory: %s', $resultDir));
            }
        }

        // ── Resolve fork level ──
        $forkLevel = '' !== $level ? ForkLevelEnum::tryFrom($level) : ForkLevelEnum::Middle;

        // ── Prepare scalar fork options (JSON-serializable for process transport) ──
        // These are passed through StartRunRequest::options to the controller.
        // The controller-side InProcessAgentSessionClient loads the snapshot
        // from fork_snapshot_path and composes fork-seeded messages.
        $forkOptions = [
            'fork_mode' => true,
            'fork_snapshot_path' => $snapshotPath,
            'fork_result_dir' => $resultDir,
            'fork_parent_run_id' => $parentRunId,
            'fork_artifact_id' => $forkRunId,
            'fork_child_run_id' => $childRunId,
            'fork_task' => '' !== $task ? $task : '',
            'fork_level' => ($forkLevel ?? ForkLevelEnum::Middle)->value,
            'fork_cwd' => getcwd(),
        ];

        // ── Start TUI with fork-seeded request (process transport) ──
        // The fork child MUST use process transport (JsonlProcessAgentSessionClient)
        // so that the run is executed by the controller subprocess.  Fork finalization
        // (handoff validation, artifact writing) is handled by ForkRunTerminalWatcher
        // in the controller process, started by StartRunHandler.
        $startRequest = new StartRunRequest(
            prompt: '', // Empty prompt — fork seed messages come via options
            runId: $childRunId,
            cwd: getcwd(),
            options: $forkOptions,
            model: '' !== $model ? $model : null,
            reasoning: '' !== $reasoning ? $reasoning : null,
        );

        // Use process transport for fork mode.
        $client = $this->resolveClient('process');

        $tuiExitCode = $this->interactiveMode->run(
            client: $client,
            request: $startRequest,
        );

        // ── Write exit marker for completion watcher (FORK-05) ──
        // The exit marker is written to stdout after the TUI closes.
        // FORK-05's completion watcher polls for this marker to detect
        // when the child process has finished.
        $exitPayload = json_encode([
            'status' => 'exited',
            'artifact_id' => $forkRunId,
            'child_run_id' => $childRunId,
            'exit_code' => $tuiExitCode,
        ], \JSON_THROW_ON_ERROR);

        fwrite(\STDOUT, "\n---FORK-RESULT-START---\n");
        fwrite(\STDOUT, $exitPayload."\n");
        fwrite(\STDOUT, "---FORK-RESULT-END---\n");
        fflush(\STDOUT);

        return $tuiExitCode;
    }

    private function runController(): int
    {
        if (null === $this->controller) {
            throw new \RuntimeException('Controller service not available');
        }

        return $this->controller->run();
    }

    private function runHeadless(OutputInterface $output): int
    {
        $stdin = fopen('php://stdin', 'rb');
        if (false === $stdin) {
            throw new \RuntimeException('Cannot open stdin for headless mode');
        }

        $output->write(JsonlCodec::encodeEvent(new RuntimeEvent(
            type: 'runtime_ready',
            runId: '',
            seq: 0,
            payload: ['version' => '1.0', 'transport' => 'in-process'],
        )));

        while ($line = fgets($stdin)) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }

            try {
                $command = JsonlCodec::decodeCommand($trimmed);
            } catch (\Throwable $e) {
                $this->logger->warning('Headless JSONL decode error', [
                    'exception' => $e,
                    'raw_input' => mb_substr($trimmed, 0, 500),
                ]);
                $output->write(JsonlCodec::encodeEvent(new RuntimeEvent(
                    type: 'protocol_error',
                    runId: '',
                    seq: 0,
                    payload: ['error' => $e->getMessage()],
                )));
                continue;
            }

            $this->handleHeadlessCommand($command, $output);
        }

        return Command::SUCCESS;
    }

    private function handleHeadlessCommand(RuntimeCommand $command, OutputInterface $output): void
    {
        match ($command->type) {
            'start_run' => $this->handleHeadlessStart($command, $output),
            'user_message' => $this->handleHeadlessMessage($command, $output),
            'cancel' => $this->handleHeadlessCancel($command, $output),
            'resume' => $this->handleHeadlessResume($command, $output),
            default => $output->write(JsonlCodec::encodeEvent(new RuntimeEvent(
                type: 'protocol_error',
                runId: $command->runId ?? '',
                seq: 0,
                payload: ['error' => \sprintf('Unknown command type: "%s"', $command->type)],
            ))),
        };
    }

    private function handleHeadlessStart(RuntimeCommand $command, OutputInterface $output): void
    {
        $prompt = (string) ($command->payload['prompt'] ?? '');
        $model = isset($command->payload['model']) ? (string) $command->payload['model'] : null;
        $reasoning = isset($command->payload['reasoning']) ? (string) $command->payload['reasoning'] : null;
        $client = $this->inProcessClient;

        // Non-blocking: dispatches StartRun to run_control transport.
        // Events flow through EventStore / publish transport, not stdout.
        $handle = $client->start(new StartRunRequest(
            prompt: $prompt,
            model: '' !== $model ? $model : null,
            reasoning: '' !== $reasoning ? $reasoning : null,
        ));

        $output->write(JsonlCodec::encodeEvent(new RuntimeEvent(
            type: 'run_started',
            runId: $handle->runId,
            seq: 1,
            payload: ['status' => 'running'],
        )));

        // Note: --headless mode does NOT forward subsequent events to stdout.
        // Use --controller for full event forwarding via EventStore drain
        // and publish transport polling.
    }

    private function handleHeadlessResume(RuntimeCommand $command, OutputInterface $output): void
    {
        $runId = $command->runId ?? '';
        if ('' === $runId) {
            $output->write(JsonlCodec::encodeEvent(new RuntimeEvent(
                type: 'protocol_error',
                runId: '',
                seq: 0,
                payload: ['error' => 'resume requires runId'],
            )));

            return;
        }

        $client = $this->inProcessClient;
        $handle = $client->attach($runId);

        $output->write(JsonlCodec::encodeEvent(new RuntimeEvent(
            type: 'run_resumed',
            runId: $handle->runId,
            seq: 1,
            payload: ['status' => $handle->status],
        )));

        // Note: --headless mode does NOT forward subsequent events to stdout.
        // Use --controller for full event forwarding.
    }

    private function handleHeadlessMessage(RuntimeCommand $command, OutputInterface $output): void
    {
        $runId = $command->runId ?? '';
        if ('' === $runId) {
            $output->write(JsonlCodec::encodeEvent(new RuntimeEvent(
                type: 'protocol_error',
                runId: '',
                seq: 0,
                payload: ['error' => 'user_message requires runId'],
            )));

            return;
        }

        $client = $this->inProcessClient;
        $client->send($runId, new UserCommand(
            type: 'message',
            text: (string) ($command->payload['text'] ?? ''),
        ));

        $output->write(JsonlCodec::encodeEvent(new RuntimeEvent(
            type: 'message_accepted',
            runId: $runId,
            seq: 0,
            payload: ['commandId' => $command->id],
        )));

        // Note: --headless mode does NOT forward subsequent events to stdout.
        // Use --controller for full event forwarding.
    }

    private function handleHeadlessCancel(RuntimeCommand $command, OutputInterface $output): void
    {
        $runId = $command->runId ?? '';
        if ('' === $runId) {
            return;
        }

        $client = $this->inProcessClient;
        $client->cancel($runId);

        $output->write(JsonlCodec::encodeEvent(new RuntimeEvent(
            type: 'run_cancelled',
            runId: $runId,
            seq: 0,
        )));
    }

    /**
     * Apply --tools and --tools-excluded CLI options to the tool registry.
     *
     * --tools is an allowlist: only these tools are visible to the model.
     * --tools-excluded is a denylist: these tools are hidden.
     * Both can be combined: final set = (allowlist or all) minus exclusions.
     *
     * Unknown tool names are rejected with a clear diagnostic before the
     * agent session starts.
     */
    private function applyToolFilters(string $tools, string $toolsExcluded): void
    {
        if (null === $this->toolRegistry) {
            if ('' !== $tools || '' !== $toolsExcluded) {
                throw new \RuntimeException('--tools and --tools-excluded require ToolRegistry to be wired.');
            }

            return;
        }

        if ('' !== $tools) {
            $this->toolRegistry->setAllowedToolNames(self::parseToolNameList($tools));
        }

        if ('' !== $toolsExcluded) {
            $this->toolRegistry->setExcludedToolNames(self::parseToolNameList($toolsExcluded));
        }
    }

    /**
     * Parse a comma-separated tool name list into a deduplicated array.
     *
     * Trims whitespace around each entry, drops empty tokens, and
     * preserves insertion order. Empty input yields an empty list.
     *
     * @return list<string>
     */
    private static function parseToolNameList(string $raw): array
    {
        return array_values(array_filter(
            array_map('\trim', explode(',', $raw)),
            static fn (string $n): bool => '' !== $n,
        ));
    }

    /**
     * Exclude the fork tool from the toolbox ONLY if it is already registered.
     *
     * ToolRegistry::setExcludedToolNames() throws for unknown names, so this
     * helper checks activeToolNames() first.  Before FORK-05 registers the fork
     * tool this is a no-op; after FORK-05 the fork tool is hidden for:
     *   - Fork child processes (nested fork guard, called from runForkTui)
     *   - In-process transport (fork cannot cross JSONL, called from __invoke)
     */
    private function excludeForkToolSafely(): void
    {
        if (null === $this->toolRegistry) {
            return;
        }

        $activeTools = $this->toolRegistry->activeToolNames();
        if (!\in_array('fork', $activeTools, true)) {
            return; // Fork tool not registered yet (pre-FORK-05) — no-op
        }

        $excluded = $this->toolRegistry->excludedToolNames();
        if (\in_array('fork', $excluded, true)) {
            return; // Already excluded
        }

        $excluded[] = 'fork';
        $this->toolRegistry->setExcludedToolNames($excluded);
    }

    private function resolveClient(string $transport): AgentSessionClient
    {
        return match ($transport) {
            'process' => $this->processClient,
            default => $this->inProcessClient,
        };
    }
}
