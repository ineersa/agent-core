<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

/**
 * Reads agent child metadata from RunStarted events.
 *
 * Encapsulates the correct payload-path traversal for the nested
 * RunStarted event shape produced by StartRunHandler:
 *
 *   $event->payload['payload']['metadata']['session'][...]
 *   $event->payload['payload']['metadata']['tools_scope'][...]
 *
 * Consumers (SubagentToolSetResolver, SubagentExecutionService) use this reader instead of raw
 * array access to avoid drift between the StartRunHandler
 * serialization shape and downstream consumers.
 */
final readonly class SubagentRunMetadataReader
{
    public function __construct(
        private EventStoreInterface $eventStore,
    ) {
    }

    /**
     * Determine whether the given run is an agent child run.
     */
    public function isAgentChild(string $runId): bool
    {
        $metadata = $this->readRunStartedMetadata($runId);
        if (null === $metadata) {
            return false;
        }

        $session = $metadata['session'] ?? [];
        if (!\is_array($session)) {
            return false;
        }

        $kind = $session['kind'] ?? null;

        return 'agent_child' === $kind;
    }

    /**
     * Parent session run id for a child run, or null when not a child.
     */
    public function readParentRunId(string $runId): ?string
    {
        $metadata = $this->readRunStartedMetadata($runId);
        if (null === $metadata) {
            return null;
        }

        $session = $metadata['session'] ?? [];
        if (!\is_array($session) || 'agent_child' !== ($session['kind'] ?? null)) {
            return null;
        }

        $parentRunId = $session['parent_run_id'] ?? null;
        if (!\is_string($parentRunId) || '' === trim($parentRunId)) {
            return null;
        }

        return $parentRunId;
    }

    /**
     * Read the allowed tool list from the child's RunStarted metadata.
     *
     * Returns null when the run is not a child or the metadata is
     * not yet available.
     *
     * @return list<string>|null
     */
    public function readAllowedTools(string $runId): ?array
    {
        $metadata = $this->readRunStartedMetadata($runId);
        if (null === $metadata) {
            return null;
        }

        $session = $metadata['session'] ?? [];
        if (!\is_array($session)) {
            return null;
        }

        if ('agent_child' !== ($session['kind'] ?? null)) {
            return null;
        }

        $toolsScope = $metadata['tools_scope'] ?? null;
        if (!\is_array($toolsScope)) {
            return null;
        }

        $tools = $toolsScope['allowed_tools'] ?? null;
        if (!\is_array($tools)) {
            return null;
        }

        /* @var list<string> */
        return $tools;
    }

    /**
     * Read and return the nested metadata payload from the RunStarted
     * event of the given run, or null if not available.
     *
     * The returned shape matches what StartRunHandler normalizes:
     *
     *   [
     *       'session'      => [...],
     *       'model'        => '...',
     *       'reasoning'    => '...',
     *       'tools_scope'  => [...],
     *   ]
     *
     * @return array<string, mixed>|null
     */
    public function readRunStartedMetadata(string $runId): ?array
    {
        $events = $this->eventStore->allFor($runId);

        foreach ($events as $event) {
            if (RunEventTypeEnum::RunStarted->value !== $event->type) {
                continue;
            }

            $inner = $event->payload['payload'] ?? null;
            if (!\is_array($inner)) {
                return null;
            }

            $metadata = $inner['metadata'] ?? null;
            if (!\is_array($metadata)) {
                return null;
            }

            return $metadata;
        }

        return null;
    }
}
