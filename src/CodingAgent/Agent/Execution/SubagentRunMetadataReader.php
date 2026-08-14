<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedMetadataDTO;
use Ineersa\CodingAgent\Extension\ChildRunExtensionAllowlistReaderInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Reads agent child metadata from RunStarted events.
 *
 * Root RunEvent.payload is denormalized via Symfony Serializer into
 * {@see RunStartedMetadataDTO} using SerializedPath attributes.
 */
final readonly class SubagentRunMetadataReader implements ChildRunExtensionAllowlistReaderInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private DenormalizerInterface $denormalizer,
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
     * Typed RunStarted metadata for the run, or null when no RunStarted event exists.
     * Malformed RunStarted payloads propagate Serializer type errors.
     */
    public function readRunStartedMetadata(string $runId): ?RunStartedMetadataDTO
    {
        $events = $this->eventStore->allFor($runId);

        foreach ($events as $event) {
            if (RunEventTypeEnum::RunStarted->value !== $event->type) {
                continue;
            }

            return $this->denormalizer->denormalize($event->payload, RunStartedMetadataDTO::class);
        }

        return null;
    }
}
