<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Command;

use Ineersa\AgentCore\Application\Dto\HotPromptStateSnapshot;
use Ineersa\AgentCore\Application\Dto\PendingCommandSnapshot;
use Ineersa\AgentCore\Application\Dto\RunDebugSnapshot;
use Ineersa\AgentCore\Application\Dto\RunStateSnapshot;
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
            $output->writeln($this->encodeJson($this->snapshotToArray($snapshot)));

            return self::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title(\sprintf('Run inspect: %s', $runId));

        if (!$snapshot->exists) {
            $io->warning('No run state, persisted events, hot prompt snapshot, or pending commands were found.');

            return self::SUCCESS;
        }

        if (null !== $snapshot->state) {
            $state = $snapshot->state;

            $io->section('State');
            $io->definitionList(
                ['status' => $state->status],
                ['version' => (string) $state->version],
                ['turn_no' => (string) $state->turnNo],
                ['last_seq' => (string) $state->lastSeq],
                ['active_step_id' => $state->activeStepId ?? 'n/a'],
                ['retryable_failure' => $state->retryableFailure ? 'yes' : 'no'],
                ['messages_count' => (string) $state->messagesCount],
                ['pending_tool_calls' => (string) $state->pendingToolCalls],
            );
        } else {
            $io->section('State');
            $io->text('No in-memory RunState found.');
        }

        $integrity = $snapshot->integrity;

        $io->section('Replay integrity');
        $io->definitionList(
            ['source' => $integrity->source],
            ['event_count' => (string) $integrity->eventCount],
            ['last_seq' => (string) $integrity->lastSeq],
            ['is_contiguous' => $integrity->isContiguous ? 'yes' : 'no'],
            ['missing_sequences' => [] === $integrity->missingSequences ? 'none' : implode(',', $integrity->missingSequences)],
        );

        if (null !== $snapshot->hotPromptState) {
            $hotPromptState = $snapshot->hotPromptState;

            $io->section('Hot prompt state');
            $io->definitionList(
                ['source' => $hotPromptState->source],
                ['event_count' => (string) $hotPromptState->eventCount],
                ['last_seq' => (string) $hotPromptState->lastSeq],
                ['token_estimate' => (string) $hotPromptState->tokenEstimate],
                ['messages_count' => (string) $hotPromptState->messagesCount],
                ['is_contiguous' => $hotPromptState->isContiguous ? 'yes' : 'no'],
                ['missing_sequences' => [] === $hotPromptState->missingSequences ? 'none' : implode(',', $hotPromptState->missingSequences)],
            );
        }

        if (\is_array($snapshot->metrics)) {
            $metrics = $snapshot->metrics;
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

        $pendingCommands = $snapshot->pendingCommands;
        $io->section('Pending commands');

        if ([] === $pendingCommands) {
            $io->text('Mailbox is empty.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($pendingCommands as $pendingCommand) {
            $rows[] = [
                $pendingCommand->idempotencyKey,
                $pendingCommand->kind,
                implode(',', $pendingCommand->payloadKeys),
                $this->encodeJson(['cancel_safe' => $pendingCommand->cancelSafe]),
            ];
        }

        $io->table(['idempotency_key', 'kind', 'payload_keys', 'options'], $rows);

        return self::SUCCESS;
    }

    /**
     * @return array{
     * run_id: string,
     * exists: bool,
     * state: array{
     * status: string,
     * version: int,
     * turn_no: int,
     * last_seq: int,
     * active_step_id: ?string,
     * retryable_failure: bool,
     * messages_count: int,
     * pending_tool_calls: int
     * }|null,
     * integrity: array{
     * run_id: string,
     * source: string,
     * event_count: int,
     * last_seq: int,
     * missing_sequences: list<int>,
     * is_contiguous: bool
     * },
     * hot_prompt_state: array{
     * source: string,
     * event_count: int,
     * last_seq: int,
     * token_estimate: int,
     * is_contiguous: bool,
     * missing_sequences: list<int>,
     * messages_count: int,
     * updated_at: ?string
     * }|null,
     * pending_commands: list<array{kind: string, idempotency_key: string, payload_keys: list<string>, options: array{cancel_safe: bool}}>,
     * metrics: array<string, mixed>|null
     * }
     */
    private function snapshotToArray(RunDebugSnapshot $snapshot): array
    {
        return [
            'run_id' => $snapshot->runId,
            'exists' => $snapshot->exists,
            'state' => $this->runStateToArray($snapshot->state),
            'integrity' => [
                'run_id' => $snapshot->integrity->runId,
                'source' => $snapshot->integrity->source,
                'event_count' => $snapshot->integrity->eventCount,
                'last_seq' => $snapshot->integrity->lastSeq,
                'missing_sequences' => $snapshot->integrity->missingSequences,
                'is_contiguous' => $snapshot->integrity->isContiguous,
            ],
            'hot_prompt_state' => $this->hotPromptStateToArray($snapshot->hotPromptState),
            'pending_commands' => array_map(
                static fn (PendingCommandSnapshot $pendingCommand): array => [
                    'kind' => $pendingCommand->kind,
                    'idempotency_key' => $pendingCommand->idempotencyKey,
                    'payload_keys' => $pendingCommand->payloadKeys,
                    'options' => ['cancel_safe' => $pendingCommand->cancelSafe],
                ],
                $snapshot->pendingCommands,
            ),
            'metrics' => $snapshot->metrics,
        ];
    }

    /**
     * @return array{
     * status: string,
     * version: int,
     * turn_no: int,
     * last_seq: int,
     * active_step_id: ?string,
     * retryable_failure: bool,
     * messages_count: int,
     * pending_tool_calls: int
     * }|null
     */
    private function runStateToArray(?RunStateSnapshot $state): ?array
    {
        if (null === $state) {
            return null;
        }

        return [
            'status' => $state->status,
            'version' => $state->version,
            'turn_no' => $state->turnNo,
            'last_seq' => $state->lastSeq,
            'active_step_id' => $state->activeStepId,
            'retryable_failure' => $state->retryableFailure,
            'messages_count' => $state->messagesCount,
            'pending_tool_calls' => $state->pendingToolCalls,
        ];
    }

    /**
     * @return array{
     * source: string,
     * event_count: int,
     * last_seq: int,
     * token_estimate: int,
     * is_contiguous: bool,
     * missing_sequences: list<int>,
     * messages_count: int,
     * updated_at: ?string
     * }|null
     */
    private function hotPromptStateToArray(?HotPromptStateSnapshot $hotPromptState): ?array
    {
        if (null === $hotPromptState) {
            return null;
        }

        return [
            'source' => $hotPromptState->source,
            'event_count' => $hotPromptState->eventCount,
            'last_seq' => $hotPromptState->lastSeq,
            'token_estimate' => $hotPromptState->tokenEstimate,
            'is_contiguous' => $hotPromptState->isContiguous,
            'missing_sequences' => $hotPromptState->missingSequences,
            'messages_count' => $hotPromptState->messagesCount,
            'updated_at' => $hotPromptState->updatedAt?->format(\DATE_ATOM),
        ];
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
