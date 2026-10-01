<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedMetadataDTO;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedSessionMetadataDTO;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedToolsScopeDTO;
use Ineersa\CodingAgent\Extension\ChildRunExtensionAllowlistReaderInterface;
use Ineersa\CodingAgent\Extension\NoninteractiveChildRunProbeInterface;
use Ineersa\CodingAgent\Session\History\HistoryProjectionStoreInterface;
use Ineersa\CodingAgent\Session\History\RunStartedLaunchProjection;

/**
 * Reads immutable RunStarted launch metadata from the shared history projection.
 *
 * Hot child/parent classification belongs on {@see \Ineersa\CodingAgent\Repository\RunRelationshipReader}.
 * This reader keeps only launch policy details that are not in the operational projection:
 * allowed tools/extensions and child model/reasoning.
 *
 * Ordinary lookups never scan the event archive. Missing/not-ready projections fail closed.
 */
final class RunStartedMetadataReader implements ChildRunExtensionAllowlistReaderInterface, NoninteractiveChildRunProbeInterface
{
    public function __construct(
        private readonly HistoryProjectionStoreInterface $historyProjectionStore,
    ) {
    }

    /**
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

    public function isNoninteractiveChildRun(?string $runId): bool
    {
        if (null === $runId || '' === $runId) {
            return false;
        }

        $metadata = $this->readRunStartedMetadata($runId);

        return true === $metadata?->isAgentChild() && false === $metadata->session->interactive;
    }

    public function readRunStartedMetadata(string $runId): ?RunStartedMetadataDTO
    {
        $launch = $this->historyProjectionStore->get($runId)->runStartedLaunch;
        if (null === $launch) {
            return null;
        }

        return $this->toDto($launch);
    }

    private function toDto(RunStartedLaunchProjection $launch): RunStartedMetadataDTO
    {
        $session = new RunStartedSessionMetadataDTO(
            kind: $launch->sessionKind,
            parentRunId: $launch->parentRunId,
            agentName: $launch->agentName,
            artifactId: $launch->artifactId,
            childKind: $launch->childKind,
            interactive: $launch->interactive,
        );

        $toolsScope = null;
        if (null !== $launch->allowedTools) {
            $toolsScope = new RunStartedToolsScopeDTO(allowedTools: $launch->allowedTools);
        }

        return new RunStartedMetadataDTO(
            session: $session,
            model: $launch->model,
            reasoning: $launch->reasoning,
            toolsScope: $toolsScope,
            contextWindow: $launch->contextWindow,
            extensions: $launch->allowedExtensions,
        );
    }
}
