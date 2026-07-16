<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Fork;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Session\SessionRunStore;

/**
 * Creates an isolated fork-local canonical session from a parent snapshot.
 *
 * Parent RunStore, EventStore, and DB row are read-only inputs. The fork-local
 * session receives rewritten run/session identity so canonical readers validate
 * directory names against embedded runId fields.
 */
final readonly class ForkSessionCopyService
{
    public function __construct(
        private HatfieldSessionStore $sessionStore,
        private SessionRunStore $runStore,
        private SessionRunEventStore $eventStore,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Copy parent canonical state and events into an existing fork-local session directory.
     *
     * The fork-local session row must already exist (typically via createSession()).
     * Parent files and metadata are never mutated.
     */
    public function copyParentSessionToForkLocal(string $parentRunId, string $forkLocalRunId): void
    {
        if ($parentRunId === $forkLocalRunId) {
            throw new \InvalidArgumentException('Fork-local run id must differ from parent run id.');
        }

        $parentSession = $this->sessionStore->findSession($parentRunId);
        if (null === $parentSession) {
            throw new \RuntimeException(\sprintf('Parent session "%s" does not exist.', $parentRunId));
        }

        $forkSession = $this->sessionStore->findSession($forkLocalRunId);
        if (null === $forkSession) {
            throw new \RuntimeException(\sprintf('Fork-local session "%s" does not exist.', $forkLocalRunId));
        }

        $parentState = $this->runStore->get($parentRunId);
        $parentEvents = $this->eventStore->allFor($parentRunId);

        $rootId = $parentSession->rootId ?? $parentRunId;

        $this->sessionStore->updateMetadata($forkLocalRunId, [
            'prompt' => $parentSession->prompt,
            'model' => $parentSession->model,
            'model_provider' => $parentSession->modelProvider,
            'model_name' => $parentSession->modelName,
            'reasoning' => $parentSession->reasoning,
            'cwd' => $parentSession->cwd,
            'parent_id' => $parentRunId,
            'root_id' => $rootId,
        ]);

        if ([] !== $parentEvents) {
            $drafts = [];
            foreach ($parentEvents as $event) {
                $drafts[] = new RunEvent(
                    runId: $forkLocalRunId,
                    seq: RunEvent::APPEND_DRAFT_SEQ,
                    turnNo: $event->turnNo,
                    type: $event->type,
                    payload: $event->payload,
                    createdAt: $event->createdAt,
                );
            }

            $this->eventStore->appendMany($drafts);
        }

        if (null !== $parentState) {
            $forkState = new RunState(
                runId: $forkLocalRunId,
                status: $parentState->status,
                version: $parentState->version,
                turnNo: $parentState->turnNo,
                lastSeq: $parentState->lastSeq,
                isStreaming: $parentState->isStreaming,
                streamingMessage: $parentState->streamingMessage,
                pendingToolCalls: $parentState->pendingToolCalls,
                errorMessage: $parentState->errorMessage,
                messages: $parentState->messages,
                activeStepId: $parentState->activeStepId,
                retryableFailure: $parentState->retryableFailure,
            );

            if (!$this->runStore->compareAndSwap($forkState, 0)) {
                throw new \RuntimeException(\sprintf('Failed to materialize fork-local state for run "%s".', $forkLocalRunId));
            }
        }
    }

    /**
     * Remove a fork-local session row and its on-disk canonical files.
     *
     * Parent sessions are unaffected. No-op when the session row is already gone.
     */
    public function removeForkLocalSession(string $forkLocalRunId): void
    {
        $entity = $this->entityManager->find(HatfieldSession::class, (int) $forkLocalRunId);
        if (null !== $entity) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }

        $sessionDir = $this->sessionStore->resolveSessionsBasePath().'/'.$forkLocalRunId;
        if (is_dir($sessionDir)) {
            $this->removeDirectoryTree($sessionDir);
        }
    }

    private function removeDirectoryTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir((string) $item);
            } else {
                unlink((string) $item);
            }
        }

        rmdir($dir);
    }
}
