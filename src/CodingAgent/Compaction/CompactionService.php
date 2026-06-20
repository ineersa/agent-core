<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\AgentCore\Contract\Compaction\CompactionPrepareResult;
use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Contract\Compaction\CompactResult;
use Ineersa\CodingAgent\Config\AppConfig;

/**
 * CodingAgent adapter that implements the AgentCore compaction contract.
 *
 * Wraps {@see SessionCompactor} and {@see CompactionConfig} to bridge
 * the CodingAgent compaction domain into the AgentCore pipeline without
 * leaking internal DTOs or namespaces into AgentCore.
 */
final readonly class CompactionService implements CompactionServiceInterface
{
    public function __construct(
        private SessionCompactor $sessionCompactor,
        private AppConfig $appConfig,
    ) {
    }

    public function prepare(array $messages): CompactionPrepareResult
    {
        $result = $this->sessionCompactor->prepare($messages, $this->appConfig->compaction);

        if (!$result->isReady()) {
            // Map CodingAgent CompactionSkipReasonEnum to a string reason
            // that AgentCore can use without importing the enum.
            $reason = $result->skipReason->value;

            return CompactionPrepareResult::failed($reason);
        }

        $prep = $result->preparation;

        return CompactionPrepareResult::ready(
            messagesToSummarize: $prep->messagesToSummarize,
            retainedTailMessages: $prep->retainedTailMessages,
            tokenEstimateBefore: $prep->tokenEstimateBefore,
            messagesCompacted: $prep->messagesCompacted,
            messagesRetained: $prep->messagesRetained,
            firstRetainedIndex: $prep->firstRetainedIndex,
            priorSummaryPresent: $prep->priorSummaryPresent,
        );
    }

    public function buildSummarizationMessages(
        CompactionPrepareResult $result,
        ?string $customInstructions,
    ): array {
        // Map the AgentCore contract DTO back to the CodingAgent
        // CompactionPreparationDTO that SessionCompactor expects.
        $preparation = new CompactionPreparationDTO(
            messagesToSummarize: $result->messagesToSummarize ?? [],
            retainedTailMessages: $result->retainedTailMessages ?? [],
            tokenEstimateBefore: $result->tokenEstimateBefore,
            messagesCompacted: $result->messagesCompacted,
            messagesRetained: $result->messagesRetained,
            firstRetainedIndex: $result->firstRetainedIndex,
            priorSummaryPresent: $result->priorSummaryPresent,
        );

        return $this->sessionCompactor->buildSummarizationMessages(
            $preparation,
            $customInstructions,
        );
    }

    public function buildCompactedMessages(
        string $summaryText,
        CompactionPrepareResult $result,
    ): CompactResult {
        $preparation = new CompactionPreparationDTO(
            messagesToSummarize: $result->messagesToSummarize ?? [],
            retainedTailMessages: $result->retainedTailMessages ?? [],
            tokenEstimateBefore: $result->tokenEstimateBefore,
            messagesCompacted: $result->messagesCompacted,
            messagesRetained: $result->messagesRetained,
            firstRetainedIndex: $result->firstRetainedIndex,
            priorSummaryPresent: $result->priorSummaryPresent,
        );

        $compacted = $this->sessionCompactor->buildCompactedMessages($summaryText, $preparation);

        return new CompactResult(
            summaryText: $compacted->summaryText,
            summaryMessage: $compacted->summaryMessage,
            compactedMessages: $compacted->compactedMessages,
            tokenEstimateBefore: $compacted->tokenEstimateBefore,
            tokenEstimateAfter: $compacted->tokenEstimateAfter,
            messagesCompacted: $compacted->messagesCompacted,
            messagesRetained: $compacted->messagesRetained,
            firstRetainedIndex: $compacted->firstRetainedIndex,
        );
    }
}
