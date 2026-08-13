<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedMetadataDecoder;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedMetadataDTO;
use Ineersa\CodingAgent\Extension\ChildRunExtensionAllowlistReaderInterface;

/**
 * Reads agent child metadata from RunStarted events.
 *
 * Encapsulates the correct payload-path traversal for the nested
 * RunStarted event shape produced by StartRunHandler:
 *
 *   $event->payload['payload']['metadata'][...]
 *
 * Decoding is delegated to {@see RunStartedMetadataDecoder} so consumers
 * share one typed representation instead of re-walking nested arrays.
 */
final readonly class SubagentRunMetadataReader implements ChildRunExtensionAllowlistReaderInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private RunStartedMetadataDecoder $decoder = new RunStartedMetadataDecoder(),
    ) {
    }

    /**
     * Determine whether the given run is an agent child run.
     */
    public function isAgentChild(string $runId): bool
    {
        $metadata = $this->readRunStartedMetadata($runId);

        return null !== $metadata && $metadata->isAgentChild();
    }

    /**
     * Parent session run id for a child run, or null when not a child.
     */
    public function readParentRunId(string $runId): ?string
    {
        $metadata = $this->readRunStartedMetadata($runId);
        if (null === $metadata || !$metadata->isAgentChild()) {
            return null;
        }

        return $metadata->session->parentRunId;
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

        return $metadata->allowedToolsForChild();
    }

    /**
     * Effective child-run extension allowlist from RunStarted metadata.
     *
     * Returns null for parent runs / missing metadata. Returns an empty list
     * when the child intentionally selected zero extensions.
     *
     * @return list<string>|null
     */
    public function readAllowedExtensions(string $runId): ?array
    {
        $metadata = $this->readRunStartedMetadata($runId);
        if (null === $metadata) {
            return null;
        }

        return $metadata->allowedExtensionsForChild();
    }

    /**
     * Typed RunStarted metadata for the run, or null when no RunStarted event
     * / nested metadata envelope is available.
     */
    public function readRunStartedMetadata(string $runId): ?RunStartedMetadataDTO
    {
        $events = $this->eventStore->allFor($runId);

        foreach ($events as $event) {
            if (RunEventTypeEnum::RunStarted->value !== $event->type) {
                continue;
            }

            return $this->decoder->fromRunEventPayload($event->payload);
        }

        return null;
    }
}
