<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Defines the immutable input payload for initiating a new agent run, encapsulating the system prompt, conversation history, and optional metadata. This class serves as a strict data contract for the Run domain, ensuring type safety for run configuration parameters.
 */
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
