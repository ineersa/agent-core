<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

final readonly class StartRunInput
{
    /**
     * Initializes the run input with system prompt, messages, optional run ID, and metadata.
     *
     * @param list<AgentMessage>   $messages
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $systemPrompt,
        public array $messages = [],
        public ?string $runId = null,
        public array $metadata = [],
    ) {
    }
}
