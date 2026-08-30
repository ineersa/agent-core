<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Repository;

use Ineersa\CodingAgent\Dto\RunRelationshipDTO;

interface RunRelationshipReaderInterface
{
    public function find(string $runId): ?RunRelationshipDTO;

    public function isAgentChild(string $runId): bool;

    public function readParentRunId(string $runId): ?string;

    public function requireKnownTopLevel(string $runId): void;
}
