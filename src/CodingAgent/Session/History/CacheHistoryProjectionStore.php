<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\History\AppliedShellCommandLookupInterface;
use Ineersa\AgentCore\Contract\History\HistoryProjectionMaintainerInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Shared disposable history projection backed by cache.app.
 *
 * Freshness is committed sequence identity on the cached snapshot.
 * TTL only bounds cleanup; healthy commits refresh/pin TTL.
 * Ordinary get/applyCommitted paths never call EventStoreInterface.
 *
 * Ordinary get() joins the per-run transition lock before observing readiness
 * so a healthy withdraw/append/publish window cannot look like interrupted
 * recovery to concurrent metadata/policy readers.
 */
final class CacheHistoryProjectionStore implements HistoryProjectionStoreInterface, HistoryProjectionMaintainerInterface, AppliedShellCommandLookupInterface
{
    private const string CACHE_KEY_PREFIX = 'hatfield.history.projection.';
    private const string LOCK_KEY_PREFIX = 'hatfield-history-projection-';
    private const int CLEANUP_TTL_SECONDS = 1800;

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly LockFactory $lockFactory,
        private readonly HistoryProjector $projector,
        private readonly RunLockManager $runLockManager,
    ) {
    }

    public function get(string $runId): HistoryProjectionSnapshot
    {
        // Wait for any in-flight commit that holds the transition lock. After
        // that lock is acquired, temporary withdraw readiness is gone for a
        // healthy publish; a still-unready snapshot means interrupted recovery.
        return $this->runLockManager->synchronized($runId, function () use ($runId): HistoryProjectionSnapshot {
            $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$runId);
            $lock->acquire(true);

            try {
                $cached = $this->readCache($runId);
                if (null === $cached) {
                    throw new \RuntimeException(\sprintf('History projection missing for run %s; initialize via startup/recovery before ordinary lookups.', $runId));
                }
                if (!$cached->ready) {
                    throw new \RuntimeException(\sprintf('History projection for run %s is not ready; recovery required.', $runId));
                }

                // Refresh under the writer lock so an older read cannot replace a
                // concurrently committed projection while extending its lifetime.
                $this->writeCache($runId, $cached);

                return $cached;
            } finally {
                $lock->release();
            }
        });
    }

    public function find(string $runId): ?HistoryProjectionSnapshot
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$runId);
        $lock->acquire(true);

        try {
            return $this->readCache($runId);
        } finally {
            $lock->release();
        }
    }

    public function remember(string $runId, HistoryProjectionSnapshot $snapshot): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$runId);
        $lock->acquire(true);

        try {
            $cached = $this->readCache($runId);
            if (null !== $cached && $cached->lastSeq > $snapshot->lastSeq) {
                return;
            }

            $this->writeCache($runId, $snapshot->withReady(true));
        } finally {
            $lock->release();
        }
    }

    public function hasAppliedShellCommand(string $runId, string $idempotencyKey): bool
    {
        $snapshot = $this->get($runId);

        return isset($snapshot->appliedShellIdempotencyKeys[$idempotencyKey]);
    }

    public function withdrawForCommit(string $runId): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$runId);
        $lock->acquire(true);

        try {
            $cached = $this->readCache($runId);
            if (null === $cached) {
                return;
            }

            $this->writeCache($runId, $cached->withReady(false));
        } finally {
            $lock->release();
        }
    }

    public function initializeFromEvents(string $runId, iterable $events): HistoryProjectionSnapshot
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$runId);
        $lock->acquire(true);

        try {
            $builder = $this->projector->createStreamBuilder();
            $seen = [];
            $maxSeq = 0;
            foreach ($events as $event) {
                if ($event->runId !== $runId) {
                    throw new \RuntimeException(\sprintf('Cannot reconstruct history for run %s: event belongs to another run.', $runId));
                }
                if (isset($seen[$event->seq])) {
                    throw new \RuntimeException(\sprintf('Cannot reconstruct history for run %s: duplicate sequence %d.', $runId, $event->seq));
                }
                if ($event->seq <= $maxSeq) {
                    throw new \RuntimeException(\sprintf('Cannot reconstruct history for run %s: events are not in canonical sequence order.', $runId));
                }
                $seen[$event->seq] = true;
                $builder->apply($event);
                $maxSeq = $event->seq;
            }

            $snapshot = $builder->finishSnapshot($maxSeq);
            $cached = $this->readCache($runId);
            if (0 === $maxSeq && null !== $cached) {
                if (0 !== $cached->lastSeq) {
                    throw new \RuntimeException(\sprintf('Cannot initialize empty history for existing run %s; recovery required.', $runId));
                }
                if (!$cached->ready) {
                    throw new \RuntimeException(\sprintf('History projection for run %s is not ready; recovery required.', $runId));
                }

                return $cached;
            }
            $readySnapshot = $snapshot->withReady(true);
            $this->writeCache($runId, $readySnapshot);

            return $readySnapshot;
        } finally {
            $lock->release();
        }
    }

    public function applyCommitted(string $runId, array $events): void
    {
        if ([] === $events) {
            return;
        }

        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$runId);
        $lock->acquire(true);

        try {
            $sorted = $events;
            usort($sorted, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);
            $maxSeq = $sorted[array_key_last($sorted)]->seq;
            $seen = [];
            foreach ($sorted as $event) {
                if ($event->runId !== $runId || $event->seq <= 0) {
                    throw new \RuntimeException(\sprintf('Invalid committed history event for run %s.', $runId));
                }
                if (isset($seen[$event->seq])) {
                    throw new \RuntimeException(\sprintf('Duplicate committed history sequence %d for run %s.', $event->seq, $runId));
                }
                $seen[$event->seq] = true;
            }

            $cached = $this->readCache($runId);
            if (null === $cached) {
                if (!$this->isBootstrapCommit($sorted)) {
                    throw new \RuntimeException(\sprintf('History projection missing for run %s while applying committed seq %d; recovery required.', $runId, $maxSeq));
                }

                $builder = $this->projector->createStreamBuilder();
                foreach ($sorted as $event) {
                    $builder->apply($event);
                }
                $this->writeCache($runId, $builder->finishSnapshot($maxSeq)->withReady(true));

                return;
            }

            if ($cached->lastSeq >= $maxSeq) {
                // Refresh TTL for healthy already-applied commits.
                $this->writeCache($runId, $cached->withReady(true));

                return;
            }

            $builder = HistoryStreamBuilder::fromSnapshot(
                $cached,
                $this->projector->eventInspectionSummarizer(),
            );
            $applied = false;
            foreach ($sorted as $event) {
                if ($event->seq <= $cached->lastSeq) {
                    continue;
                }
                // Allocation can reserve sequences that never reach the log.
                // A sequence hole is not evidence of a missing committed event.
                $builder->apply($event);
                $applied = true;
            }
            if (!$applied) {
                return;
            }

            $this->writeCache($runId, $builder->finishSnapshot($maxSeq)->withReady(true));
        } finally {
            $lock->release();
        }
    }

    /**
     * @param list<RunEvent> $sorted ascending by seq
     */
    private function isBootstrapCommit(array $sorted): bool
    {
        return RunEventTypeEnum::RunStarted->value === $sorted[0]->type;
    }

    private function readCache(string $runId): ?HistoryProjectionSnapshot
    {
        $item = $this->pool->getItem(self::CACHE_KEY_PREFIX.$runId);
        if (!$item->isHit()) {
            return null;
        }

        $payload = $item->get();
        if (!\is_array($payload)) {
            return null;
        }

        $lastSeq = $payload['last_seq'] ?? null;
        $retained = $payload['retained_turn_nos'] ?? null;
        $prompts = $payload['prompts_by_turn_no'] ?? null;
        $position = $payload['position_turn_no'] ?? null;
        $initialPrompt = $payload['initial_prompt'] ?? null;
        $pendingHumanPrompt = $payload['pending_human_prompt'] ?? null;
        $ready = $payload['ready'] ?? true;
        $eventCount = $payload['event_count'] ?? 0;
        $sanitizedEventTail = $payload['sanitized_event_tail'] ?? [];
        $eligibleTokens = $payload['eligible_auto_compaction_input_tokens'] ?? null;
        $issuedReminderKeys = $payload['issued_reminder_keys'] ?? [];
        $appliedShellKeys = $payload['applied_shell_idempotency_keys'] ?? [];
        $runStartedLaunchPayload = $payload['run_started_launch'] ?? null;
        if (!\is_int($lastSeq) || !\is_array($retained) || !\is_array($prompts) || !\is_int($position)) {
            return null;
        }
        if (null !== $initialPrompt && !\is_string($initialPrompt)) {
            return null;
        }
        if (null !== $pendingHumanPrompt && !\is_string($pendingHumanPrompt)) {
            return null;
        }
        if (!\is_bool($ready)) {
            return null;
        }
        if (!\is_int($eventCount) || $eventCount < 0) {
            return null;
        }
        if (!\is_array($sanitizedEventTail)) {
            return null;
        }
        if (null !== $eligibleTokens && (!\is_int($eligibleTokens) || $eligibleTokens <= 0)) {
            return null;
        }
        if (!\is_array($issuedReminderKeys) || !\is_array($appliedShellKeys)) {
            return null;
        }
        $normalizedReminderKeys = [];
        foreach ($issuedReminderKeys as $key) {
            if (!\is_string($key) || !\in_array($key, ['early', 'urgent'], true)) {
                return null;
            }
            if (!\in_array($key, $normalizedReminderKeys, true)) {
                $normalizedReminderKeys[] = $key;
            }
        }
        $normalizedShellKeys = [];
        foreach ($appliedShellKeys as $key) {
            if (!\is_string($key) || '' === $key) {
                return null;
            }
            $normalizedShellKeys[$key] = true;
        }
        $runStartedLaunch = null;
        if (null !== $runStartedLaunchPayload) {
            if (!\is_array($runStartedLaunchPayload)) {
                return null;
            }
            $runStartedLaunch = $this->decodeRunStartedLaunch($runStartedLaunchPayload);
            if (null === $runStartedLaunch) {
                return null;
            }
        }
        $normalizedTail = [];
        foreach ($sanitizedEventTail as $row) {
            if (!\is_array($row)) {
                return null;
            }
            $seq = $row['seq'] ?? null;
            $turnNo = $row['turn_no'] ?? null;
            $type = $row['type'] ?? null;
            $createdAt = $row['created_at'] ?? null;
            $summary = $row['summary'] ?? null;
            if (!\is_int($seq) || !\is_int($turnNo) || !\is_string($type) || !\is_string($createdAt) || !\is_string($summary)) {
                return null;
            }
            $normalizedTail[] = [
                'seq' => $seq,
                'turn_no' => $turnNo,
                'type' => $type,
                'created_at' => $createdAt,
                'summary' => $summary,
            ];
        }

        $retainedTurnNos = [];
        foreach ($retained as $turnNo) {
            if (!\is_int($turnNo)) {
                return null;
            }
            $retainedTurnNos[] = $turnNo;
        }

        $promptsByTurnNo = [];
        foreach ($prompts as $turnNo => $text) {
            if (!\is_string($text)) {
                return null;
            }
            $turnKey = filter_var($turnNo, \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE);
            if (null === $turnKey) {
                return null;
            }
            $promptsByTurnNo[$turnKey] = $text;
        }

        return new HistoryProjectionSnapshot(
            history: new HistoryDTO(
                retainedTurnNos: $retainedTurnNos,
                promptsByTurnNo: $promptsByTurnNo,
                positionTurnNo: $position,
            ),
            lastSeq: $lastSeq,
            initialPrompt: $initialPrompt,
            pendingHumanPrompt: $pendingHumanPrompt,
            ready: $ready,
            eventCount: $eventCount,
            sanitizedEventTail: $normalizedTail,
            eligibleAutoCompactionInputTokens: $eligibleTokens,
            issuedReminderKeys: $normalizedReminderKeys,
            appliedShellIdempotencyKeys: $normalizedShellKeys,
            runStartedLaunch: $runStartedLaunch,
        );
    }

    private function writeCache(string $runId, HistoryProjectionSnapshot $snapshot): void
    {
        $item = $this->pool->getItem(self::CACHE_KEY_PREFIX.$runId);
        $item->set([
            'last_seq' => $snapshot->lastSeq,
            'retained_turn_nos' => $snapshot->history->retainedTurnNos,
            'prompts_by_turn_no' => $snapshot->history->promptsByTurnNo,
            'position_turn_no' => $snapshot->history->positionTurnNo,
            'initial_prompt' => $snapshot->initialPrompt,
            'pending_human_prompt' => $snapshot->pendingHumanPrompt,
            'ready' => $snapshot->ready,
            'event_count' => $snapshot->eventCount,
            'sanitized_event_tail' => $snapshot->sanitizedEventTail,
            'eligible_auto_compaction_input_tokens' => $snapshot->eligibleAutoCompactionInputTokens,
            'issued_reminder_keys' => $snapshot->issuedReminderKeys,
            'applied_shell_idempotency_keys' => array_keys($snapshot->appliedShellIdempotencyKeys),
            'run_started_launch' => null === $snapshot->runStartedLaunch ? null : [
                'model' => $snapshot->runStartedLaunch->model,
                'reasoning' => $snapshot->runStartedLaunch->reasoning,
                'context_window' => $snapshot->runStartedLaunch->contextWindow,
                'session_kind' => $snapshot->runStartedLaunch->sessionKind,
                'child_kind' => $snapshot->runStartedLaunch->childKind,
                'parent_run_id' => $snapshot->runStartedLaunch->parentRunId,
                'agent_name' => $snapshot->runStartedLaunch->agentName,
                'artifact_id' => $snapshot->runStartedLaunch->artifactId,
                'interactive' => $snapshot->runStartedLaunch->interactive,
                'allowed_tools' => $snapshot->runStartedLaunch->allowedTools,
                'allowed_extensions' => $snapshot->runStartedLaunch->allowedExtensions,
            ],
        ]);
        $item->expiresAfter(self::CLEANUP_TTL_SECONDS);
        if (!$this->pool->save($item)) {
            throw new \RuntimeException(\sprintf('Cannot publish history projection for run %s; recovery required.', $runId));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function decodeRunStartedLaunch(array $payload): ?RunStartedLaunchProjection
    {
        $model = $payload['model'] ?? null;
        if (!\is_string($model) || '' === trim($model)) {
            return null;
        }

        $reasoning = $payload['reasoning'] ?? null;
        if (null !== $reasoning && !\is_string($reasoning)) {
            return null;
        }
        $contextWindow = $payload['context_window'] ?? null;
        if (null !== $contextWindow && (!\is_int($contextWindow) || $contextWindow <= 0)) {
            return null;
        }
        $sessionKind = $payload['session_kind'] ?? 'main';
        if (!\is_string($sessionKind) || '' === $sessionKind) {
            return null;
        }
        $childKind = $payload['child_kind'] ?? null;
        if (null !== $childKind && !\is_string($childKind)) {
            return null;
        }
        $parentRunId = $payload['parent_run_id'] ?? null;
        if (null !== $parentRunId && !\is_string($parentRunId)) {
            return null;
        }
        $agentName = $payload['agent_name'] ?? null;
        if (null !== $agentName && !\is_string($agentName)) {
            return null;
        }
        $artifactId = $payload['artifact_id'] ?? null;
        if (null !== $artifactId && !\is_string($artifactId)) {
            return null;
        }
        $interactive = $payload['interactive'] ?? true;
        if (!\is_bool($interactive)) {
            return null;
        }

        $allowedTools = $payload['allowed_tools'] ?? null;
        if (null !== $allowedTools) {
            if (!\is_array($allowedTools)) {
                return null;
            }
            foreach ($allowedTools as $tool) {
                if (!\is_string($tool)) {
                    return null;
                }
            }
            $allowedTools = array_values($allowedTools);
        }

        $allowedExtensions = $payload['allowed_extensions'] ?? null;
        if (null !== $allowedExtensions) {
            if (!\is_array($allowedExtensions)) {
                return null;
            }
            foreach ($allowedExtensions as $extension) {
                if (!\is_string($extension)) {
                    return null;
                }
            }
            $allowedExtensions = array_values($allowedExtensions);
        }

        return new RunStartedLaunchProjection(
            model: trim($model),
            reasoning: null === $reasoning ? null : trim($reasoning),
            contextWindow: $contextWindow,
            sessionKind: $sessionKind,
            childKind: $childKind,
            parentRunId: $parentRunId,
            agentName: $agentName,
            artifactId: $artifactId,
            interactive: $interactive,
            allowedTools: $allowedTools,
            allowedExtensions: $allowedExtensions,
        );
    }
}
