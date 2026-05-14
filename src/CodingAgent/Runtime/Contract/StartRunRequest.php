<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Request DTO for starting a new agent run.
 *
 * When $runId is provided, it becomes both the session ID and the
 * agent-core run ID. The directory .hatfield/sessions/<runId>/
 * must be created before the run starts (handled by HatfieldSessionStore).
 *
 * When $runId is empty/omitted, the AgentSessionClient implementation
 * generates a new UUID run ID.
 */
final readonly class StartRunRequest
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $prompt,
        public string $runId = '',
        public string $cwd = '',
        public array $options = [],
    ) {
    }
}
