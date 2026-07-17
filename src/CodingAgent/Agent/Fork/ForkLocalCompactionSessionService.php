<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Psr\Log\LoggerInterface;

/**
 * Creates a temporary fork-local canonical session for AgentRunner::compact, and cleans it up.
 *
 * Correlation for async continuation is stored only in RunStarted metadata (no extra tables).
 */
final readonly class ForkLocalCompactionSessionService
{
    public const string SESSION_KIND = 'fork_compaction';

    public function __construct(
        private HatfieldSessionStore $sessionStore,
        private RunStoreInterface $runStore,
        private EventStoreInterface $eventStore,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{
     *     parent_run_id: string,
     *     parent_tool_call_id: string,
     *     lifecycle_id: string,
     *     task: string,
     *     model_override?: ?string,
     *     reasoning_override?: ?string
     * } $correlation
     * @param list<AgentMessage> $sanitizedMessages
     */
    public function createSeededSession(array $correlation, array $sanitizedMessages, ?string $model, ?string $reasoning): string
    {
        $localRunId = $this->sessionStore->createSession('fork_compaction');

        $metadata = new RunMetadata(
            session: [
                'kind' => self::SESSION_KIND,
                'parent_run_id' => $correlation['parent_run_id'],
                'parent_tool_call_id' => $correlation['parent_tool_call_id'],
                'lifecycle_id' => $correlation['lifecycle_id'],
                'task' => $correlation['task'],
                'model_override' => $correlation['model_override'] ?? null,
                'reasoning_override' => $correlation['reasoning_override'] ?? null,
            ],
            model: $model,
            reasoning: $reasoning,
        );

        // Replay-consistent terminal seed so ApplyCommand(Compact) applies immediately
        // (non-active status) instead of mailbox-queuing forever.
        $runStarted = new RunEvent(
            runId: $localRunId,
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'step_id' => 'fork-local-seed',
                'payload' => [
                    'metadata' => [
                        'session' => $metadata->session,
                        'model' => $metadata->model,
                        'reasoning' => $metadata->reasoning,
                        'tools_scope' => null,
                        'context_window' => null,
                    ],
                    'system_prompt' => '',
                    'messages' => array_map(
                        static fn (AgentMessage $message): array => $message->toArray(),
                        $sanitizedMessages,
                    ),
                ],
            ],
        );
        $agentEnd = new RunEvent(
            runId: $localRunId,
            seq: 2,
            turnNo: 0,
            type: RunEventTypeEnum::AgentEnd->value,
            payload: ['reason' => 'completed'],
        );

        $this->eventStore->appendMany([$runStarted, $agentEnd]);

        $seeded = new RunState(
            runId: $localRunId,
            status: RunStatus::Completed,
            version: 1,
            turnNo: 0,
            lastSeq: 2,
            messages: $sanitizedMessages,
        );
        if (!$this->runStore->compareAndSwap($seeded, 0)) {
            throw new \RuntimeException(\sprintf('Failed to seed fork-local RunState for "%s".', $localRunId));
        }

        return $localRunId;
    }

    public function cleanup(string $localRunId): void
    {
        if ('' === trim($localRunId)) {
            return;
        }

        $sessionDir = $this->sessionStore->resolveSessionsBasePath().'/'.$localRunId;
        if (is_dir($sessionDir)) {
            $this->removeDirectoryTree($sessionDir);
        }

        $entity = $this->sessionStore->findSession($localRunId);
        if ($entity instanceof HatfieldSession) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    /**
     * Best-effort cleanup after successful child start: log structured degradation, do not throw.
     */
    public function cleanupBestEffort(string $localRunId, string $parentRunId, string $parentToolCallId): void
    {
        try {
            $this->cleanup($localRunId);
        } catch (\Throwable $e) {
            $this->logger->warning('fork_local_compaction.cleanup_failed', [
                'run_id' => $parentRunId,
                'tool_call_id' => $parentToolCallId,
                'fork_local_run_id' => $localRunId,
                'component' => 'agent.execution',
                'event_type' => 'fork_local_compaction.cleanup_failed',
                'exception_class' => $e::class,
            ]);
        }
    }

    private function removeDirectoryTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if (false === $items) {
            throw new \RuntimeException(\sprintf('Failed to scan fork-local session directory "%s".', $dir));
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.\DIRECTORY_SEPARATOR.$item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectoryTree($path);
            } elseif (!@unlink($path) && file_exists($path)) {
                throw new \RuntimeException(\sprintf('Failed to remove fork-local path "%s".', $path));
            }
        }

        if (!@rmdir($dir) && is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Failed to remove fork-local directory "%s".', $dir));
        }
    }
}
