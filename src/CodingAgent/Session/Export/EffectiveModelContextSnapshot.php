<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Export;

/**
 * Effective model-visible context for HTML session export.
 *
 * Messages are serialized AgentMessage::toArray() shapes so TuiExport can render
 * without importing AgentCore message types.
 */
final readonly class EffectiveModelContextSnapshot
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param list<string>|null          $availableTools null = no retained snapshot; [] = authoritative empty list
     * @param array<string, mixed>|null  $compaction     Metadata from the latest context_compacted event
     */
    public function __construct(
        public array $messages,
        public ?array $availableTools = null,
        public ?int $availableToolsSchemaTokensEstimate = null,
        public ?array $compaction = null,
    ) {
    }
}
