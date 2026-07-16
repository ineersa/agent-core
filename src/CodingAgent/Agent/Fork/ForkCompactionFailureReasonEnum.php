<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

/**
 * Typed failure reason for fork virtual compaction surfaced to the fork tool.
 */
enum ForkCompactionFailureReasonEnum: string
{
    case NoSummarizationModel = 'no_summarization_model';
    case PreparationFailed = 'preparation_failed';
    case NoCompactableMessages = 'no_compactable_messages';
    case NoSafeBoundary = 'no_safe_boundary';
    case SummarizationPlatformFailed = 'summarization_platform_failed';
    case EmptySummaryText = 'empty_summary_text';
    case IneffectiveSummary = 'ineffective_summary';
}