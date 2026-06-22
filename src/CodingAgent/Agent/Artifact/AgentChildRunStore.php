<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Parent-scoped RunStoreInterface implementation for child agent runs.
 *
 * Stores RunState at the parent-scoped artifact path:
 *
 *   .hatfield/sessions/<parentRunId>/artifacts/agents/<artifactId>/state.json
 *
 * Uses Symfony Lock (FlockStore) keyed by the child agentRunId to
 * protect atomic compareAndSwap operations.
 *
 * Does NOT create top-level .hatfield/sessions/<agentRunId>/
 * directories — child state is entirely parent-scoped.
 *
 * The embedded runId inside state.json must match the bound agentRunId.
 * Mismatches throw on read.  get() only returns results for the bound
 * agentRunId; other run IDs return null.
 */
final class AgentChildRunStore implements RunStoreInterface
{
    private readonly string $sessionsBasePath;

    public function __construct(
        HatfieldSessionStore $hatfieldSessionStore,
        private readonly NormalizerInterface&DenormalizerInterface $serializer,
        private readonly LockFactory $lockFactory,
        /** Parent session run ID. */
        private readonly string $parentRunId,
        /** Child agent run ID (embedded in RunState->runId). */
        private readonly string $agentRunId,
        /** Artifact directory name within artifacts/agents/. */
        private readonly string $artifactId,
    ) {
        $this->sessionsBasePath = $hatfieldSessionStore->resolveSessionsBasePath();
    }

    public function get(string $runId): ?RunState
    {
        if ($runId !== $this->agentRunId) {
            return null;
        }

        $path = $this->statePath();

        if (!is_readable($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if (false === $json) {
            return null;
        }

        // Empty or whitespace-only file is "no state yet".
        if ('' === trim($json)) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(\sprintf('Corrupt state.json for child run "%s" — not parseable as JSON: %s', $this->agentRunId, $e->getMessage()), previous: $e);
        }

        if (!\is_array($data)) {
            return null;
        }

        /** @var RunState $state */
        $state = $this->serializer->denormalize($data, RunState::class);

        // Validate embedded runId matches the bound agentRunId.
        if ($state->runId !== $this->agentRunId) {
            throw new \RuntimeException(\sprintf('RunState integrity error: embedded runId "%s" does not match bound agentRunId "%s".', $state->runId, $this->agentRunId));
        }

        return $state;
    }

    public function compareAndSwap(RunState $state, int $expectedVersion): bool
    {
        if ($state->runId !== $this->agentRunId) {
            throw new \RuntimeException(\sprintf('RunState integrity error: embedded runId "%s" does not match bound agentRunId "%s".', $state->runId, $this->agentRunId));
        }

        $lock = $this->lockFactory->createLock("hatfield-run-{$this->agentRunId}");
        $lock->acquire(true);

        try {
            $current = $this->get($this->agentRunId);
            $currentVersion = null === $current ? 0 : $current->version;

            if ($currentVersion !== $expectedVersion) {
                return false;
            }

            $data = $this->serializer->normalize($state);
            $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

            $path = $this->statePath();
            $dir = \dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            file_put_contents($path, $json, \LOCK_EX);

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Find the bound child run if it is running and its state.json has
     * not been updated since the given timestamp.
     *
     * This store is bound to exactly one child (parentRunId + agentRunId +
     * artifactId).  Returns [state] when stale and Running, [] otherwise.
     * Does NOT scan sibling artifacts — upstream callers iterate the
     * registry for multi-child scanning.
     *
     * @return list<RunState>
     */
    public function findRunningStaleBefore(\DateTimeImmutable $updatedBefore): array
    {
        $path = $this->statePath();

        if (!is_file($path)) {
            return [];
        }

        $mtime = filemtime($path);
        if (false === $mtime) {
            return [];
        }

        $lastModified = \DateTimeImmutable::createFromFormat('U', (string) $mtime);
        if (false === $lastModified || $lastModified > $updatedBefore) {
            return [];
        }

        $state = $this->get($this->agentRunId);
        if (null === $state) {
            return [];
        }

        if (RunStatus::Running !== $state->status) {
            return [];
        }

        return [$state];
    }

    /**
     * Resolve the state.json path for this child artifact.
     *
     * Returns: <sessionsBase>/<parentRunId>/artifacts/agents/<artifactId>/state.json
     */
    private function statePath(): string
    {
        $paths = AgentArtifactPathsDTO::forArtifactId($this->artifactId);

        return $this->sessionsBasePath.'/'.$this->parentRunId.'/'.$paths->statePath;
    }
}
