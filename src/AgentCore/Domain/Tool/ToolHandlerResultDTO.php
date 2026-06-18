<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Structured envelope for tool handler return values.
 *
 * Tools that need to convey structured metadata alongside their display
 * text (e.g. output cap notices, system notifications) return this DTO
 * instead of a bare string. The display text is used as the human-readable
 * tool output; the details array carries structured metadata for downstream
 * projection (system_notices, output_cap fields, etc.).
 *
 * Tool handlers that do not need structured metadata should continue
 * returning bare strings for simplicity. ToolExecutor::toDomainResult()
 * handles both forms transparently.
 */
final readonly class ToolHandlerResultDTO
{
    /**
     * @param array<string, mixed> $details Tool result metadata. Known keys:
     *                                      - system_notices?: list<array> of structured notice payloads
     *                                      - output_cap?: bool
     *                                      - output_cap_limit?: int
     *                                      - output_cap_char_count?: int
     *                                      - output_cap_saved_path?: string
     */
    public function __construct(
        public string $text,
        public array $details = [],
    ) {
    }
}
