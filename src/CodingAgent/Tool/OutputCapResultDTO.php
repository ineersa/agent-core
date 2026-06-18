<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Structured result from OutputCap::processDetailed().
 *
 * Carries both the output text (original or capped notice) and the
 * structured cap metadata that downstream consumers use for projection
 * and TUI display — no text parsing required.
 */
final readonly class OutputCapResultDTO
{
    /**
     * @param string      $text      The output text: original content if
     *                               under cap, or the model-facing capped
     *                               notice if capped
     * @param bool        $capped    Whether the output exceeded the cap
     * @param int|null    $limit     The character cap that was applied
     * @param int|null    $charCount Total character count of the original
     *                               output before capping
     * @param string|null $savedPath Absolute path where full output was
     *                               persisted for audit (null if not capped)
     */
    public function __construct(
        public string $text,
        public bool $capped,
        public ?int $limit = null,
        public ?int $charCount = null,
        public ?string $savedPath = null,
    ) {
    }

    /**
     * Build a structured notice payload suitable for system_notices
     * metadata or system.notice runtime event projection.
     *
     * Returns null when the output was not capped (no notice needed).
     *
     * @param string|null $toolCallId Optional tool call ID for deduplication
     *
     * @return array<string, mixed>|null
     */
    public function toNoticePayload(?string $toolCallId = null): ?array
    {
        if (!$this->capped) {
            return null;
        }

        $payload = [
            'text' => $this->text,
            'source' => 'output_cap',
            'notice_type' => 'output_cap',
            'severity' => 'warning',
        ];

        if (null !== $toolCallId) {
            $payload['tool_call_id'] = $toolCallId;
        }
        if (null !== $this->limit) {
            $payload['output_cap_limit'] = $this->limit;
        }
        if (null !== $this->charCount) {
            $payload['output_cap_char_count'] = $this->charCount;
        }
        if (null !== $this->savedPath) {
            $payload['output_cap_saved_path'] = $this->savedPath;
        }

        return $payload;
    }
}
