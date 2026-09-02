<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Export;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Effective model-visible context for HTML session export.
 */
final readonly class EffectiveModelContextSnapshot
{
    /**
     * @param list<AgentMessage>        $messages
     * @param list<string>|null         $availableTools
     * @param array<string, mixed>|null $compaction     Metadata from the latest context_compacted event
     */
    public function __construct(
        public array $messages,
        public ?array $availableTools = null,
        public ?int $availableToolsSchemaTokensEstimate = null,
        public ?array $compaction = null,
    ) {
    }
}
