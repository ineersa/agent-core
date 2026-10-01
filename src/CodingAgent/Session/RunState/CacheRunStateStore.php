<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\RunState;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Domain\Run\RunState;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Shared disposable current RunState backed by cache.app.
 *
 * Freshness is committed sequence identity on the cached state.
 * TTL only bounds cleanup; healthy publishes refresh/pin TTL.
 * Ordinary get/remember paths never call EventStoreInterface.
 *
 * Cache entries carry a ready flag. withdrawForCommit() marks the pre-append
 * state not-ready so an interrupted publication window cannot look healthy.
 * Ordinary ready lookups join the per-run transition lock so concurrent
 * readers wait for a healthy commit instead of mistaking temporary withdrawal
 * for interrupted recovery.
 */
final class CacheRunStateStore implements RunStateStoreInterface
{
    private const string CACHE_KEY_PREFIX = 'hatfield.run_state.';
    private const string LOCK_KEY_PREFIX = 'hatfield-run-state-';
    private const int CLEANUP_TTL_SECONDS = 1800;

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly LockFactory $lockFactory,
        private readonly NormalizerInterface&DenormalizerInterface $serializer,
        private readonly RunLockManager $runLockManager,
    ) {
    }

    public function get(string $runId): RunState
    {
        return $this->findReady($runId)
            ?? throw new \RuntimeException(\sprintf('Run state projection missing for run %s; initialize via startup/new-run/recovery before ordinary lookups.', $runId));
    }

    public function find(string $runId): ?RunState
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$runId);
        $lock->acquire(true);

        try {
            $cached = $this->readEntry($runId);

            return $cached['state'] ?? null;
        } finally {
            $lock->release();
        }
    }

    public function isReady(string $runId): bool
    {
        try {
            return null !== $this->findReady($runId);
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'not ready')) {
                return false;
            }

            throw $exception;
        }
    }

    public function remember(RunState $state): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$state->runId);
        $lock->acquire(true);

        try {
            $cached = $this->readEntry($state->runId);
            if (null !== $cached && $cached['state']->lastSeq > $state->lastSeq) {
                throw new \RuntimeException(\sprintf('Cannot publish run state for run %s at seq %d; shared projection is already at seq %d.', $state->runId, $state->lastSeq, $cached['state']->lastSeq));
            }

            $this->writeEntry($state, true);
        } finally {
            $lock->release();
        }
    }

    public function initialize(RunState $state): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$state->runId);
        $lock->acquire(true);

        try {
            $cached = $this->readEntry($state->runId);
            if (null !== $cached) {
                if ($cached['ready'] && $this->serializer->normalize($cached['state']) === $this->serializer->normalize($state)) {
                    $this->writeEntry($cached['state'], true);

                    return;
                }

                throw new \RuntimeException(\sprintf('Cannot initialize run state for run %s; shared projection already exists at seq %d.', $state->runId, $cached['state']->lastSeq));
            }

            $this->writeEntry($state, true);
        } finally {
            $lock->release();
        }
    }

    public function invalidate(string $runId): void
    {
        if (!$this->pool->deleteItem(self::CACHE_KEY_PREFIX.$runId)) {
            throw new \RuntimeException(\sprintf('Cannot invalidate run state projection for run %s.', $runId));
        }
    }

    public function withdrawForCommit(string $runId): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$runId);
        $lock->acquire(true);

        try {
            $cached = $this->readEntry($runId);
            if (null === $cached) {
                return;
            }

            $this->writeEntry($cached['state'], false);
        } finally {
            $lock->release();
        }
    }

    private function findReady(string $runId): ?RunState
    {
        return $this->runLockManager->synchronized($runId, function () use ($runId): ?RunState {
            $lock = $this->lockFactory->createLock(self::LOCK_KEY_PREFIX.$runId);
            $lock->acquire(true);

            try {
                $cached = $this->readEntry($runId);
                if (null === $cached) {
                    return null;
                }
                if (!$cached['ready']) {
                    throw new \RuntimeException(\sprintf('Run state projection for run %s is not ready; recovery required.', $runId));
                }

                // Refresh under the writer lock so an older read cannot replace a
                // concurrently committed projection while extending its lifetime.
                $this->writeEntry($cached['state'], true);

                return $cached['state'];
            } finally {
                $lock->release();
            }
        });
    }

    /**
     * @return array{state: RunState, ready: bool}|null
     */
    private function readEntry(string $runId): ?array
    {
        $item = $this->pool->getItem(self::CACHE_KEY_PREFIX.$runId);
        if (!$item->isHit()) {
            return null;
        }

        $payload = $item->get();
        if (!\is_array($payload)) {
            throw new \RuntimeException(\sprintf('Invalid run state projection payload for run %s.', $runId));
        }

        $ready = $payload['ready'] ?? true;
        if (!\is_bool($ready)) {
            throw new \RuntimeException(\sprintf('Invalid run state projection readiness for run %s.', $runId));
        }

        $statePayload = $payload['state'] ?? $payload;
        if (!\is_array($statePayload)) {
            throw new \RuntimeException(\sprintf('Invalid run state projection payload for run %s.', $runId));
        }

        try {
            $state = $this->serializer->denormalize($statePayload, RunState::class);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(\sprintf('Cannot decode run state projection for run %s; recovery required.', $runId), previous: $exception);
        }

        if (!$state instanceof RunState || $state->runId !== $runId) {
            throw new \RuntimeException(\sprintf('Run state projection identity mismatch for run %s.', $runId));
        }

        return ['state' => $state, 'ready' => $ready];
    }

    private function writeEntry(RunState $state, bool $ready): void
    {
        $normalized = $this->serializer->normalize($state);
        if (!\is_array($normalized)) {
            throw new \RuntimeException(\sprintf('Cannot normalize run state for run %s.', $state->runId));
        }

        $item = $this->pool->getItem(self::CACHE_KEY_PREFIX.$state->runId);
        $item->set([
            'ready' => $ready,
            'state' => $normalized,
        ]);
        $item->expiresAfter(self::CLEANUP_TTL_SECONDS);
        if (!$this->pool->save($item)) {
            throw new \RuntimeException(\sprintf('Cannot publish run state projection for run %s; recovery required.', $state->runId));
        }
    }
}
