<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Repair;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
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
                'replayOk' => false,
                'message' => '',
                'backupEventsPath' => null,
                'backupStatePath' => null,
            ];

            if ([] === $duplicateSeqs && 0 === $dropped) {
                $report['replayOk'] = $this->canReplay($runId);
                $report['message'] = $report['replayOk']
                    ? 'No known repairable corruption detected.'
                    : 'No duplicate sequences detected, but replay still fails.';

                return $report;
            }

            if (!$apply) {
                $report['message'] = \sprintf(
                    'Repair preview: duplicate seq(s): %s; droppable replay-failure agent_end: %d. Re-run with apply to repair.',
                    implode(', ', array_map('strval', $duplicateSeqs)),
                    $dropped,
                );

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
                $repaired = $this->buildRepairedLines($parsed);
                $report['renumberedEvents'] = \count($repaired);
                $this->writeLinesAtomic($eventsPath, $repaired);

                $state = $this->runStore->get($runId);
                if (null === $state) {
                    throw new \RuntimeException(\sprintf('Cannot repair run %s: state.json missing.', $runId));
                }

                $replay = $this->runStateRebuilder->rebuildIfStale($state, $runId);
                if (!$replay->rebuilt || null === $replay->rebuiltState) {
                    throw new \RuntimeException(\sprintf('Repair validation failed for run %s: replay did not rebuild state.', $runId));
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
                $report['message'] = \sprintf(
                    'Session repaired. Backup: %s%s',
                    $backupEvents,
                    null !== $backupStateTarget ? ' and '.$backupStateTarget : '',
                );

                $this->logger->info('session.repair.applied', [
                    'run_id' => $runId,
                    'duplicate_seqs' => $duplicateSeqs,
                    'dropped_replay_failure_ends' => $dropped,
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
