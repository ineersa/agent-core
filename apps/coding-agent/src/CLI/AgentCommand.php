<?php

declare(strict_types=1);

namespace App\CLI;

use App\Runtime\Contract\AgentSessionClient;
use App\Runtime\Contract\StartRunRequest;
use App\Runtime\Contract\UserCommand;
use App\Runtime\InProcess\InProcessAgentSessionClient;
use App\Runtime\Process\JsonlProcessAgentSessionClient;
use App\Runtime\Protocol\JsonlCodec;
use App\Runtime\Protocol\RuntimeCommand;
use App\Runtime\Protocol\RuntimeEvent;
use App\TUI\InteractiveMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Unified agent command — TUI (default) or headless JSONL mode.
 *
 * Usage:
 *   agent                         # Interactive TUI mode (in-process transport)
 *   agent --headless              # JSONL protocol on stdin/stdout
 *   agent --prompt="Do X"         # TUI with initial prompt
 *   agent --resume=<runId>        # Resume existing run
 *   agent --transport=process     # Use process-isolated transport in TUI mode
 */
#[AsCommand(name: 'agent', description: 'Agent session — TUI (default) or headless JSONL runtime')]
final class AgentCommand
{
    public function __construct(
        private InProcessAgentSessionClient $inProcessClient,
        private JsonlProcessAgentSessionClient $processClient,
        private InteractiveMode $interactiveMode,
    ) {
    }

    public function __invoke(
        #[Option(description: 'Run in headless JSONL protocol mode (stdin/stdout)')]
        bool $headless = false,

        #[Option(description: 'Transport type: "in-process" (default) or "process"')]
        string $transport = 'in-process',

        #[Option(description: 'Initial prompt for a new run')]
        string $prompt = '',

        #[Option(description: 'Resume an existing run by ID')]
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

        $handle = null;
        if ('' !== $resume) {
            $handle = $client->resume($resume);
            $output->writeln(\sprintf('<info>Resumed run %s</info>', $resume));
        } elseif ('' !== $prompt) {
            $handle = $client->start(new StartRunRequest(prompt: $prompt));
            $output->writeln(\sprintf('<info>Started run %s</info>', $handle->runId));
        }

        if (null !== $handle) {
            $output->writeln(\sprintf('Run ID: <comment>%s</comment>', $handle->runId));
        }

        return $this->interactiveMode->run($client);
    }

    private function runHeadless(OutputInterface $output): int
    {
        $stdin = fopen('php://stdin', 'r');
        if (false === $stdin) {
            throw new \RuntimeException('Cannot open stdin for headless mode');
        }

        // Write protocol header — one event to signal readiness
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
