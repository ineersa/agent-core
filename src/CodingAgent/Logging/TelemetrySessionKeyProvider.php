<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

/** Project-qualified telemetry identity; domain session and run IDs remain unchanged. */
final readonly class TelemetrySessionKeyProvider
{
    private string $projectHash;

    public function __construct(string $runtimeCwd)
    {
        $cwd = realpath($runtimeCwd);
        if (false === $cwd) {
            throw new \InvalidArgumentException('Telemetry project directory cannot be resolved.');
        }
        $this->projectHash = hash('sha256', $cwd);
    }

    public function forSession(string $sessionId): string
    {
        return $this->projectHash.':'.$sessionId;
    }
}
