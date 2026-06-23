<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

/**
 * Retrieval output modes for {@see AgentArtifactRetrievalService}.
 */
enum AgentRetrieveModeEnum: string
{
    case Handoff = 'handoff';
    case Metadata = 'metadata';
    case Events = 'events';
    case History = 'history';
    case Debug = 'debug';
}
