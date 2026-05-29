<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * File-backed RunStoreInterface implementation.
 *
 * Stores RunState at .hatfield/sessions/<runId>/state.json.
 * Uses Symfony Lock (FlockStore) for atomic compareAndSwap.
 *
 * Directory name is canonical; the embedded runId inside state.json
 * is validated on read. Mismatches cause runtime errors.
 *
 * Uses HatfieldSessionStore::resolveSessionsBasePath() as the single
 * source of truth for the sessions directory, ensuring all session
 * stores write to the same location.
 */
final class SessionRunStore implements RunStoreInterface
{
    private readonly string $sessionsBasePath;

    public function __construct(
        HatfieldSessionStore $hatfieldSessionStore,
        private readonly NormalizerInterface&DenormalizerInterface $serializer,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
        $this->sessionsBasePath = $hatfieldSessionStore->resolveSessionsBasePath();
    }

    public function get(string $runId): ?RunState
    {
        $path = $this->statePath($runId);

        if (!is_readable($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if (false === $json) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger->warning('SessionRunStore cannot decode corrupt state.json', [
                'run_id' => $runId,
                'path' => $path,
            ]);

            return null;
        }

        if (!\is_array($data)) {
            return null;
        }

        /** @var RunState $state */
        $state = $this->serializer->denormalize($data, RunState::class);

        // Validate embedded runId matches directory (canonical source)
        if ($state->runId !== $runId) {
            throw new \RuntimeException(\sprintf('RunState integrity error: embedded runId "%s" does not match directory "%s".', $state->runId, $runId));
        }

        return $state;
    }

    public function compareAndSwap(RunState $state, int $expectedVersion): bool
    {
        $lock = $this->lockFactory->createLock('hatfield-run-'.$state->runId);
        $lock->acquire(true);

        try {
            $current = $this->get($state->runId);
            $currentVersion = null === $current ? 0 : $current->version;

            if ($currentVersion !== $expectedVersion) {
                return false;
            }

            $data = $this->serializer->normalize($state);
            $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

            $path = $this->statePath($state->runId);
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

    public function findRunningStaleBefore(\DateTimeImmutable $updatedBefore): array
    {
        $sessionsDir = $this->sessionsDir();
        if (!is_dir($sessionsDir)) {
            return [];
        }

        $stale = [];

        foreach (new \DirectoryIterator($sessionsDir) as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }

            $runId = $item->getFilename();
            $statePath = $this->statePath($runId);

            if (!is_file($statePath)) {
                continue;
            }

            $mtime = filemtime($statePath);
            if (false === $mtime) {
                continue;
            }

            $lastModified = \DateTimeImmutable::createFromFormat('U', (string) $mtime);
            if (false === $lastModified || $lastModified > $updatedBefore) {
                continue;
            }

            $state = $this->get($runId);
            if (null === $state) {
                continue;
            }

            if (RunStatus::Running !== $state->status) {
                continue;
            }

            $stale[] = $state;
        }

        return $stale;
    }

    private function sessionsDir(): string
    {
        return $this->sessionsBasePath;
    }

    private function statePath(string $runId): string
    {
        return $this->sessionsDir().'/'.$runId.'/state.json';
    }
}
