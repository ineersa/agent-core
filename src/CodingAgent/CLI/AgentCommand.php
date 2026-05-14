<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Application\InteractiveMode;
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
 *   agent --resume=<sessionId>         # Resume existing session (loads transcript)
 *   agent --transport=process          # Use process-isolated transport in TUI mode
 *
 * Session persistence:
 *   Every TUI session creates a directory under .hatfield/sessions/<session-id>/
 *   containing metadata.yaml, transcript.jsonl, and runtime-events.jsonl.
 *   Use --resume to reload a previous session with its full transcript.
 */
#[AsCommand(name: 'agent', description: 'Agent session — TUI (default) or headless JSONL runtime')]
final class AgentCommand
{
    public function __construct(
        private InProcessAgentSessionClient $inProcessClient,
        private JsonlProcessAgentSessionClient $processClient,
        private InteractiveMode $interactiveMode,
        private HatfieldSessionStore $sessionStore,
    ) {
    }

    public function __invoke(
        #[Option(description: 'Run in headless JSONL protocol mode (stdin/stdout)')]
        bool $headless = false,

        #[Option(description: 'Transport type: "in-process" (default) or "process"')]
        string $transport = 'in-process',

        #[Option(description: 'Initial prompt for a new run')]
        string $prompt = '',

        #[Option(description: 'Resume an existing session by ID')]
        string $resume = '',

        ?OutputInterface $output = null,
    ): int {
        if (null === $output) {
            throw new \RuntimeException('AgentCommand requires OutputInterface');
        }

        if ($headless) {
            return $this->runHeadless($output);
        }

        return $this->runTui($transport, $prompt, $resume, $output);
    }

    private function runTui(string $transport, string $prompt, string $resume, OutputInterface $output): int
    {
        $client = $this->resolveClient($transport);
        $projectCwd = getcwd() ?: '';

        $sessionId = '';
        if ('' !== $resume) {
            $sessionId = $resume;
            if (!$this->sessionStore->exists($projectCwd, $sessionId)) {
                throw new \RuntimeException(\sprintf('Session not found: "%s". Use --prompt to start a new session.', $sessionId));
            }
        }

        return $this->interactiveMode->run(
            client: $client,
            output: $output,
            request: '' !== $prompt ? new StartRunRequest(prompt: $prompt, cwd: $projectCwd) : null,
            sessionId: $sessionId,
            projectCwd: $projectCwd,
        );
    }

    private function runHeadless(OutputInterface $output): int
    {
        $stdin = fopen('php://stdin', 'r');
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
        $client = $this->inProcessClient;
        $handle = $client->start(new StartRunRequest(prompt: $prompt));

        $output->write(JsonlCodec::encodeEvent(new RuntimeEvent(
            type: 'run_started',
            runId: $handle->runId,
            seq: 1,
            payload: ['status' => 'running'],
        )));

        foreach ($client->events($handle->runId) as $event) {
            $output->write(JsonlCodec::encodeEvent($event));
        }
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
        $handle = $client->resume($runId);

        $output->write(JsonlCodec::encodeEvent(new RuntimeEvent(
            type: 'run_resumed',
            runId: $handle->runId,
            seq: 1,
            payload: ['status' => 'running'],
        )));

        foreach ($client->events($handle->runId) as $event) {
            $output->write(JsonlCodec::encodeEvent($event));
        }
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

        foreach ($client->events($runId) as $event) {
            $output->write(JsonlCodec::encodeEvent($event));
        }
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
}
