<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Bootstrap;

/** Suppress full owner stdout payloads before sealing and while the spool awaits mount. */
final class SessionBootstrapEmissionGate
{
    private ?string $preparingRunId = null;

    public function __construct(private readonly SessionBootstrapSpoolStore $spools)
    {
    }

    public function prepare(string $runId): void
    {
        $this->preparingRunId = $runId;
    }

    public function release(): void
    {
        $this->preparingRunId = null;
    }

    public function holds(string $runId): bool
    {
        return $runId === $this->preparingRunId || $this->spools->isActive($runId);
    }
}
