<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedMetadataDTO;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Detects foreground non-interactive child subagent runs for extension hooks.
 *
 * Child workers inherit HATFIELD_APPROVAL_CHANNEL from the parent controller;
 * SafeGuard must not enter RequireApproval for those runs.
 */
final readonly class NoninteractiveChildRunProbe
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private DenormalizerInterface $denormalizer,
    ) {
    }

    public function isNoninteractiveChildRun(?string $runId): bool
    {
        if (null === $runId || '' === $runId) {
            return false;
        }

        $event = $this->eventStore->firstFor($runId);
        if (null === $event || RunEventTypeEnum::RunStarted->value !== $event->type) {
            return false;
        }

        $metadata = $this->denormalizer->denormalize($event->payload, RunStartedMetadataDTO::class);
        if (!$metadata->isAgentChild()) {
            return false;
        }

        // Child session.interactive defaults to true when omitted.
        return false === $metadata->session->interactive;
    }
}
