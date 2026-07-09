<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Repair;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Psr\Log\LoggerInterface;

/**
 * Centralized session event-log repair for known corruption patterns.
 */
final readonly class SessionRepairService
{
    public function __construct(
        private HatfieldSessionStore $sessionStore,
        private RunStoreInterface $runStore,
        private RunStateRebuilderInterface $runStateRebuilder,
        private ReplayEventPreparer $replayEventPreparer,
        private EventPayloadNormalizer $eventPayloadNormalizer,
        private RunLockManager $lockManager,
        private RunStateReducer $runStateReducer,
        private EventFactory $eventFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *     runId: string,
     *     apply: bool,
     *     duplicateSeqs: list<int>,
     *     droppedReplayFailureEnds: int,
     *     renumberedEvents: int,
     *     staleCancellationRepaired: bool,
     *     appendedTerminalEvents: int,
     *     replayOk: bool,
     *     message: string,
     *     backupEventsPath: string|null,
     *     backupStatePath: string|null
     * }
     */
    public function repair(string $runId, bool $apply = false): array
    {
        $eventsPath = $this->eventsPath($runId);
        if (!is_readable($eventsPath)) {
            throw new \RuntimeException(\sprintf('Cannot repair run %s: events.jsonl not found.', $runId));
        }

        return $this->lockManager->synchronized($runId, function () use ($runId, $eventsPath, $apply): array {
            $rawLines = $this->readPhysicalLines($eventsPath);
            $parsed = $this->parseLines($runId, $rawLines);
            $duplicateSeqs = $this->findDuplicateSeqs($parsed);
            $dropped = $this->countDroppableReplayFailureEnds($parsed);

            $report = [
                'runId' => $runId,
                'apply' => $apply,
                'duplicateSeqs' => $duplicateSeqs,
                'droppedReplayFailureEnds' => $dropped,
                'renumberedEvents' => 0,
                'staleCancellationRepaired' => false,
                'appendedTerminalEvents' => 0,
                'replayOk' => false,
                'message' => '',
                'backupEventsPath' => null,
                'backupStatePath' => null,
            ];

            $needsSeqRepair = [] !== $duplicateSeqs || $dropped > 0;
            $staleCancellation = $this->detectStaleCancellation($runId, $parsed);
            $needsStaleCancellationRepair = $staleCancellation['needsRepair'];

            if (!$needsSeqRepair && !$needsStaleCancellationRepair) {
                $report['replayOk'] = $this->canReplay($runId);
                $report['message'] = $report['replayOk']
                    ? 'No known repairable corruption detected.'
                    : 'No duplicate sequences detected, but replay still fails.';

                return $report;
            }

            if (!$apply) {
                $parts = [];
                if ($needsSeqRepair) {
                    $parts[] = \sprintf(
                        'duplicate seq(s): %s; droppable replay-failure agent_end: %d',
                        implode(', ', array_map('strval', $duplicateSeqs)),
                        $dropped,
                    );
                }
                if ($needsStaleCancellationRepair) {
                    $parts[] = 'stale non-terminal cancellation (missing terminal agent_end)';
                }
                $report['message'] = 'Repair preview: '.implode('; ', $parts).'. Run /repair to apply.';

                return $report;
            }

            $backupStamp = (new \DateTimeImmutable())->format('Ymd-His');
            $backupEvents = $eventsPath.'.bak-'.$backupStamp;
            $backupState = $this->statePath($runId);
            $backupStateTarget = is_readable($backupState) ? $backupState.'.bak-'.$backupStamp : null;

            if (!copy($eventsPath, $backupEvents)) {
                throw new \RuntimeException(\sprintf('Failed to backup events for run %s.', $runId));
            }
            if (null !== $backupStateTarget && !copy($backupState, $backupStateTarget)) {
                throw new \RuntimeException(\sprintf('Failed to backup state for run %s.', $runId));
            }

            try {
                $lines = $needsSeqRepair
                    ? $this->buildRepairedLines($parsed)
                    : $this->buildLinesFromParsed($parsed);

                if ($needsStaleCancellationRepair) {
                    $lines = $this->appendStaleCancellationTerminalEvents(
                        $runId,
                        $lines,
                        $staleCancellation['turnNo'],
                        $staleCancellation['activeStepId'],
                    );
                    $report['staleCancellationRepaired'] = true;
                    $report['appendedTerminalEvents'] = null !== $staleCancellation['activeStepId'] ? 2 : 1;
                }

                $report['renumberedEvents'] = \count($lines);
                $this->writeLinesAtomic($eventsPath, $lines);

                $state = $this->runStore->get($runId);
                if (null === $state) {
                    throw new \RuntimeException(\sprintf('Cannot repair run %s: state.json missing.', $runId));
                }

                $replay = $this->runStateRebuilder->rebuildIfStale($state, $runId);
                if (!$replay->rebuilt || null === $replay->rebuiltState) {
                    throw new \RuntimeException(\sprintf('Repair validation failed for run %s: replay did not rebuild state.', $runId));
                }

                if (RunStatus::Cancelled !== $replay->rebuiltState->status && $needsStaleCancellationRepair) {
                    throw new \RuntimeException(\sprintf('Repair validation failed for run %s: expected cancelled status after stale cancellation repair, got %s.', $runId, $replay->rebuiltState->status->value));
                }

                if (!$this->runStore->compareAndSwap($replay->rebuiltState, $state->version)) {
                    $latest = $this->runStore->get($runId) ?? $state;
                    if (!$this->runStore->compareAndSwap($replay->rebuiltState, $latest->version)) {
                        throw new \RuntimeException(\sprintf('Repair validation failed for run %s: could not persist rebuilt state.', $runId));
                    }
                }

                $report['replayOk'] = true;
                $report['backupEventsPath'] = $backupEvents;
                $report['backupStatePath'] = $backupStateTarget;
                $messageParts = ['Session repaired.'];
                if ($report['staleCancellationRepaired']) {
                    $messageParts[] = 'Stale cancellation terminalized to cancelled.';
                }
                $messageParts[] = 'Backup: '.$backupEvents.(null !== $backupStateTarget ? ' and '.$backupStateTarget : '');
                $report['message'] = implode(' ', $messageParts);

                $this->logger->info('session.repair.applied', [
                    'run_id' => $runId,
                    'duplicate_seqs' => $duplicateSeqs,
                    'dropped_replay_failure_ends' => $dropped,
                    'stale_cancellation_repaired' => $report['staleCancellationRepaired'],
                    'appended_terminal_events' => $report['appendedTerminalEvents'],
                    'component' => 'session.repair',
                    'event_type' => 'session.repair.applied',
                ]);

                return $report;
            } catch (\Throwable $e) {
                copy($backupEvents, $eventsPath);
                if (null !== $backupStateTarget && is_readable($backupStateTarget)) {
                    copy($backupStateTarget, $backupState);
                }

                throw new \RuntimeException(\sprintf('Repair failed for run %s and backups were restored: %s', $runId, $e->getMessage()), previous: $e);
            }
        });
    }

    private function canReplay(string $runId): bool
    {
        $state = $this->runStore->get($runId);
        if (null === $state) {
            return false;
        }

        try {
            $replay = $this->runStateRebuilder->rebuildIfStale($state, $runId);

            return $replay->rebuilt || null !== $replay->rebuiltState;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<array{lineNo:int, payload:array<string,mixed>|null, raw:string, drop:bool}> $parsed
     *
     * @return array{needsRepair: bool, turnNo: int, activeStepId: string|null}
     */
    private function detectStaleCancellation(string $runId, array $parsed): array
    {
        $events = $this->denormalizedEventsFromParsed($parsed);
        if ([] === $events) {
            return ['needsRepair' => false, 'turnNo' => 0, 'activeStepId' => null];
        }

        $sorted = $this->replayEventPreparer->sortBySequence($events);
        if ([] !== $this->replayEventPreparer->duplicateSequences($sorted)) {
            return ['needsRepair' => false, 'turnNo' => 0, 'activeStepId' => null];
        }

        $seed = new RunState(runId: $runId, status: RunStatus::Queued, version: 0, turnNo: 0, lastSeq: 0);
        $replayed = $this->runStateReducer->replay($seed, $sorted);

        if (RunStatus::Cancelling !== $replayed->status) {
            return ['needsRepair' => false, 'turnNo' => 0, 'activeStepId' => null];
        }

        if ($this->hasTerminalAgentEndAfterLastCancel($sorted)) {
            return ['needsRepair' => false, 'turnNo' => 0, 'activeStepId' => null];
        }

        if ($replayed->isStreaming || \in_array(false, $replayed->pendingToolCalls, true)) {
            return ['needsRepair' => false, 'turnNo' => 0, 'activeStepId' => null];
        }

        return [
            'needsRepair' => true,
            'turnNo' => $replayed->turnNo,
            'activeStepId' => $replayed->activeStepId,
        ];
    }

    /**
     * @param list<RunEvent> $sortedEvents
     */
    private function hasTerminalAgentEndAfterLastCancel(array $sortedEvents): bool
    {
        $lastCancelSeq = null;
        foreach ($sortedEvents as $event) {
            if (RunEventTypeEnum::AgentCommandApplied->value === $event->type
                && 'cancel' === ($event->payload['kind'] ?? null)) {
                $lastCancelSeq = $event->seq;
            }
        }

        if (null === $lastCancelSeq) {
            return false;
        }

        foreach ($sortedEvents as $event) {
            if ($event->seq <= $lastCancelSeq) {
                continue;
            }
            if (RunEventTypeEnum::AgentEnd->value !== $event->type) {
                continue;
            }

            $reason = $event->payload['reason'] ?? null;

            return \in_array($reason, ['cancelled', 'completed', 'failed'], true);
        }

        return false;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function appendStaleCancellationTerminalEvents(
        string $runId,
        array $lines,
        int $turnNo,
        ?string $activeStepId,
    ): array {
        $maxSeq = 0;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }
            /** @var array<string, mixed> $payload */
            $payload = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
            $seq = $payload['seq'] ?? 0;
            if (\is_int($seq) && $seq > $maxSeq) {
                $maxSeq = $seq;
            }
        }

        $specs = [];
        if (null !== $activeStepId && '' !== $activeStepId) {
            $specs[] = [
                'type' => RunEventTypeEnum::LlmStepAborted->value,
                'payload' => [
                    'step_id' => $activeStepId,
                    'stop_reason' => 'aborted',
                    'usage' => [],
                    'aborted_assistant' => null,
                ],
            ];
        }

        $specs[] = [
            'type' => RunEventTypeEnum::AgentEnd->value,
            'payload' => [
                'reason' => 'cancelled',
            ],
        ];

        $events = $this->eventFactory->eventsFromSpecs($runId, $turnNo, $maxSeq + 1, $specs);
        foreach ($events as $event) {
            $lines[] = json_encode($this->eventPayloadNormalizer->normalizeRunEvent($event), \JSON_THROW_ON_ERROR);
        }

        return $lines;
    }

    /**
     * @param list<array{lineNo:int, payload:array<string,mixed>|null, raw:string, drop:bool}> $parsed
     *
     * @return list<string>
     */
    private function buildLinesFromParsed(array $parsed): array
    {
        $out = [];
        foreach ($parsed as $row) {
            if ($row['drop'] || !\is_array($row['payload'])) {
                continue;
            }
            $out[] = json_encode($row['payload'], \JSON_THROW_ON_ERROR);
        }

        return $out;
    }

    /**
     * @param list<array{lineNo:int, payload:array<string,mixed>|null, raw:string, drop:bool}> $parsed
     *
     * @return list<RunEvent>
     */
    private function denormalizedEventsFromParsed(array $parsed): array
    {
        $events = [];
        foreach ($parsed as $row) {
            if ($row['drop'] || !\is_array($row['payload'])) {
                continue;
            }
            $event = $this->eventPayloadNormalizer->denormalizeRunEvent($row['payload']);
            if (null !== $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @param list<array{lineNo:int, payload:array<string,mixed>|null, raw:string, drop:bool}> $parsed
     *
     * @return list<string>
     */
    private function buildRepairedLines(array $parsed): array
    {
        $kept = [];
        foreach ($parsed as $row) {
            if ($row['drop']) {
                continue;
            }
            if (!\is_array($row['payload'])) {
                continue;
            }
            $kept[] = $row;
        }

        $nextSeq = 1;
        $out = [];
        foreach ($kept as $row) {
            $payload = $row['payload'];
            $payload['seq'] = $nextSeq;
            $out[] = json_encode($payload, \JSON_THROW_ON_ERROR);
            ++$nextSeq;
        }

        return $out;
    }

    /**
     * @param list<string> $rawLines
     *
     * @return list<array{lineNo: int, payload: array<string, mixed>|null, raw: string, drop: bool}>
     */
    private function parseLines(string $runId, array $rawLines): array
    {
        $parsed = [];
        foreach ($rawLines as $lineNo => $raw) {
            $trimmed = trim($raw);
            if ('' === $trimmed) {
                continue;
            }

            try {
                /** @var array<string, mixed> $payload */
                $payload = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $parsed[] = ['lineNo' => $lineNo, 'payload' => null, 'raw' => $raw, 'drop' => false];
                continue;
            }

            $drop = $this->shouldDropReplayFailureAgentEnd($payload);
            $parsed[] = ['lineNo' => $lineNo, 'payload' => $payload, 'raw' => $raw, 'drop' => $drop];
        }

        return $parsed;
    }

    /**
     * @param list<array{lineNo:int, payload:array<string,mixed>|null, raw:string, drop:bool}> $parsed
     *
     * @return list<int>
     */
    private function findDuplicateSeqs(array $parsed): array
    {
        $events = [];
        foreach ($parsed as $row) {
            if ($row['drop'] || !\is_array($row['payload'])) {
                continue;
            }
            $event = $this->eventPayloadNormalizer->denormalizeRunEvent($row['payload']);
            if (null !== $event) {
                $events[] = $event;
            }
        }

        return $this->replayEventPreparer->duplicateSequences($events);
    }

    /**
     * @param list<array{lineNo:int, payload:array<string,mixed>|null, raw:string, drop:bool}> $parsed
     */
    private function countDroppableReplayFailureEnds(array $parsed): int
    {
        $count = 0;
        foreach ($parsed as $row) {
            if ($row['drop']) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function shouldDropReplayFailureAgentEnd(array $payload): bool
    {
        if (($payload['type'] ?? null) !== 'agent_end') {
            return false;
        }

        $error = $payload['payload']['error'] ?? '';
        if (!\is_string($error)) {
            return false;
        }

        return str_contains($error, 'duplicate sequence number')
            || str_contains($error, 'duplicate sequence number(s)');
    }

    /** @return list<string> */
    private function readPhysicalLines(string $path): array
    {
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException('Failed to read events.jsonl.');
        }

        return explode("\n", rtrim($contents, "\n"));
    }

    /** @param list<string> $lines */
    private function writeLinesAtomic(string $path, array $lines): void
    {
        $tmp = $path.'.repair-tmp-'.bin2hex(random_bytes(4));
        $payload = [] === $lines ? '' : implode("\n", $lines)."\n";
        if (false === file_put_contents($tmp, $payload, \LOCK_EX)) {
            throw new \RuntimeException('Failed to write repaired events.jsonl.');
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to replace events.jsonl with repaired file.');
        }
    }

    private function eventsPath(string $runId): string
    {
        return $this->sessionStore->resolveSessionsBasePath().'/'.$runId.'/events.jsonl';
    }

    private function statePath(string $runId): string
    {
        return $this->sessionStore->resolveSessionsBasePath().'/'.$runId.'/state.json';
    }
}
