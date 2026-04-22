<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Command;

use Ineersa\AgentCore\Application\Handler\RunDebugService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'agent-loop:run-inspect', description: 'Inspect run state, mailbox, and replay integrity.')]
final class AgentLoopRunInspectCommand extends Command
{
    public function __construct(private readonly RunDebugService $runDebugService)
    {
        parent::__construct();

        $this
            ->addArgument('runId', InputArgument::REQUIRED, 'Run identifier.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit raw JSON output.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runId = (string) $input->getArgument('runId');
        $snapshot = $this->runDebugService->inspect($runId);

        if (true === $input->getOption('json')) {
            $output->writeln($this->encodeJson($snapshot));

            return self::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title(\sprintf('Run inspect: %s', $runId));

        if (false === $snapshot['exists']) {
            $io->warning('No run state, persisted events, hot prompt snapshot, or pending commands were found.');

            return self::SUCCESS;
        }

        if (\is_array($snapshot['state'])) {
            $state = $snapshot['state'];

            $io->section('State');
            $io->definitionList(
                ['status' => (string) $state['status']],
                ['version' => (string) $state['version']],
                ['turn_no' => (string) $state['turn_no']],
                ['last_seq' => (string) $state['last_seq']],
                ['active_step_id' => (string) ($state['active_step_id'] ?? 'n/a')],
                ['retryable_failure' => true === $state['retryable_failure'] ? 'yes' : 'no'],
                ['messages_count' => (string) $state['messages_count']],
                ['pending_tool_calls' => (string) $state['pending_tool_calls']],
            );
        } else {
            $io->section('State');
            $io->text('No in-memory RunState found.');
        }

        $integrity = $snapshot['integrity'];

        $io->section('Replay integrity');
        $io->definitionList(
            ['source' => (string) $integrity['source']],
            ['event_count' => (string) $integrity['event_count']],
            ['last_seq' => (string) $integrity['last_seq']],
            ['is_contiguous' => true === $integrity['is_contiguous'] ? 'yes' : 'no'],
            ['missing_sequences' => [] === $integrity['missing_sequences'] ? 'none' : implode(',', $integrity['missing_sequences'])],
        );

        if (\is_array($snapshot['hot_prompt_state'])) {
            $hotPromptState = $snapshot['hot_prompt_state'];

            $io->section('Hot prompt state');
            $io->definitionList(
                ['source' => (string) ($hotPromptState['source'] ?? 'unknown')],
                ['event_count' => (string) $hotPromptState['event_count']],
                ['last_seq' => (string) $hotPromptState['last_seq']],
                ['token_estimate' => (string) $hotPromptState['token_estimate']],
                ['messages_count' => (string) $hotPromptState['messages_count']],
                ['is_contiguous' => true === $hotPromptState['is_contiguous'] ? 'yes' : 'no'],
                ['missing_sequences' => [] === $hotPromptState['missing_sequences'] ? 'none' : implode(',', $hotPromptState['missing_sequences'])],
            );
        }

        if (\is_array($snapshot['metrics'])) {
            $metrics = $snapshot['metrics'];
            $llm = \is_array($metrics['llm'] ?? null) ? $metrics['llm'] : [];
            $tools = \is_array($metrics['tools'] ?? null) ? $metrics['tools'] : [];
            $queueLag = \is_array($metrics['command_queue_lag'] ?? null) ? $metrics['command_queue_lag'] : [];

            $io->section('Observability metrics');
            $io->definitionList(
                ['active_runs_by_status' => $this->encodeJson(\is_array($metrics['active_runs_by_status'] ?? null) ? $metrics['active_runs_by_status'] : [])],
                ['stale_result_count' => (string) ($metrics['stale_result_count'] ?? 0)],
                ['replay_rebuild_count' => (string) ($metrics['replay_rebuild_count'] ?? 0)],
                ['llm_error_rate' => (string) ($llm['error_rate'] ?? 0.0)],
                ['tool_timeout_rate' => (string) ($tools['timeout_rate'] ?? 0.0)],
                ['command_queue_lag_max' => (string) ($queueLag['max'] ?? 0)],
            );
        }

        $pendingCommands = $snapshot['pending_commands'];
        $io->section('Pending commands');

        if ([] === $pendingCommands) {
            $io->text('Mailbox is empty.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($pendingCommands as $pendingCommand) {
            $rows[] = [
                (string) $pendingCommand['idempotency_key'],
                (string) $pendingCommand['kind'],
                implode(',', $pendingCommand['payload_keys']),
                $this->encodeJson($pendingCommand['options']),
            ];
        }

        $io->table(['idempotency_key', 'kind', 'payload_keys', 'options'], $rows);

        return self::SUCCESS;
    }

    /**
     * Encodes a payload as readable JSON for command output.
     *
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload): string
    {
        $encoded = json_encode($payload, \JSON_PRETTY_PRINT);

        return false === $encoded ? '{}' : $encoded;
    }
}
