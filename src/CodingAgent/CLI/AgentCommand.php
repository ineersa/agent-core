<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI;

use Ineersa\CodingAgent\Config\AppConfig;
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
use Ineersa\CodingAgent\Tool\ToolFilterRuntimeConfig;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use Ineersa\Tui\Application\InteractiveMode;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Unified agent command — interactive TUI by default, plus headless/controller modes.
 *
 * Public installs expose this as the default `hatfield` entrypoint. Session data
 * lives under .hatfield/sessions/<session-id>/events.jsonl.
 */
#[AsCommand(
    name: 'agent',
    description: 'Launch an interactive Hatfield coding-agent session',
    help: <<<'HELP'
Starts the Hatfield coding-agent TUI in the current directory (or --cwd).
Headless/controller options are for automation and internal process topology.

  hatfield
  hatfield --cwd=/path/to/project
  hatfield --resume=<session-id>

  hatfield list              List available commands
  hatfield help <command>    Show help for a specific command
HELP
)]
final class AgentCommand
{
    public function __construct(
        private InProcessAgentSessionClient $inProcessClient,
        private JsonlProcessAgentSessionClient $processClient,
        private InteractiveMode $interactiveMode,
        private HatfieldSessionStore $sessionStore,
        private SkillsConfig $skillsConfig,
        private PromptTemplatesRuntimeConfig $promptTemplatesConfig,
        private ToolFilterRuntimeConfig $toolFilterConfig,
        private LoggerInterface $logger,
        private readonly AppConfig $appConfig,
        private readonly ?StartupDatabaseMigrator $startupDatabaseMigrator = null,
        private ?HeadlessController $controller = null,
        private readonly ?ToolRegistryInterface $toolRegistry = null,
    ) {
    }

    /**
     * @param list<string> $skillsPath
     * @param list<string> $skills
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

        #[Option(description: 'Reasoning/thinking level: off, minimal, low, medium, high, xhigh, max')]
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

        ?OutputInterface $output = null,
    ): int {
        if (null === $output) {
            throw new \RuntimeException('AgentCommand requires OutputInterface');
        }

        if (!$this->hasEnabledAiProvider()) {
            $io = new SymfonyStyle(new ArgvInput(), $output);
            $io->error('No AI providers configured. Run `hatfield providers:setup` to enable one.');

            return Command::FAILURE;
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
            // Canonical state is also stored for process-transport controller/worker
            // propagation (argv + dedicated internal env).
            $this->toolFilterConfig->applyFromCli($tools, $toolsExcluded, $this->toolRegistry);

            // Run pending database migrations once on agent startup.
            // StartupDatabaseMigrator is idempotent per process lifetime and
            // safe for concurrent controller+consumer processes.
            // Applies explicit generated application then transport migration lists.
            // Running here ensures migrations complete before any
            // controller/TUI/headless path accesses the DB.
            if (null !== $this->startupDatabaseMigrator) {
                ($this->startupDatabaseMigrator)();
            }

            // Extension loading is handled by ExtensionLoaderSubscriber
            // (fires on ConsoleEvents::COMMAND) which loads extensions in
            // every process including messenger:consume workers.

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

        // Parent entrypoint allocates the session with the real prompt.
        $runId = $this->sessionStore->createSession($prompt);

        // Non-blocking: dispatches StartRun to run_control transport.
        // Events flow through EventStore / publish transport, not stdout.
        $handle = $client->start(new StartRunRequest(
            prompt: $prompt,
            runId: $runId,
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

    private function resolveClient(string $transport): AgentSessionClient
    {
        return match ($transport) {
            'process' => $this->processClient,
            default => $this->inProcessClient,
        };
    }

    private function hasEnabledAiProvider(): bool
    {
        $ai = $this->appConfig->ai;
        if (null === $ai) {
            return false;
        }

        foreach ($ai->providers as $provider) {
            if ($provider->enabled) {
                return true;
            }
        }

        return false;
    }
}
