<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Structured result of an OutputCap capping operation.
 *
 * Carries the exact model-facing notice text plus the original output
 * metrics so consumers (e.g. ToolResultProcessor, LLM transform hook)
 * can construct ModelNotificationDTOs without parsing the notice string.
 */
final readonly class OutputCapResult
{
    public function __construct(
        /** Absolute path to the persisted full-output file. */
        public string $savedPath,
        /** Character cap that was applied. */
        public int $cap,
        /** Original character count (before capping). */
        public int $charCount,
        /** Estimated token count (chars ÷ 4, ceil). */
        public int $tokenEstimate,
        /** Exact text the model receives as the tool result. */
        public string $noticeText,
    ) {
    }
}
