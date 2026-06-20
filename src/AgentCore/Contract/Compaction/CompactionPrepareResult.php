<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Compaction;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Result of compaction preparation from the compaction service.
 *
 * Either contains a ready preparation (partitioned messages) or a
 * structural skip reason. AgentCore pipeline handlers use this to
 * decide whether to proceed with model invocation or emit a failure.
 */
final readonly class CompactionPrepareResult
{
    /**
     * @param list<AgentMessage>|null $messagesToSummarize  Messages to be summarized away
     * @param list<AgentMessage>|null $retainedTailMessages Messages kept as-is after compaction
     */
    private function __construct(
        public ?array $messagesToSummarize,
        public ?array $retainedTailMessages,

        /** Approximate token count before compaction. */
        public int $tokenEstimateBefore,

        /** Number of messages being summarized away. */
        public int $messagesCompacted,

        /** Number of messages retained in the tail. */
        public int $messagesRetained,

        /** Index of the first message in the retained tail. */
        public int $firstRetainedIndex,

        /** Whether the input messages already contain a prior compact summary. */
        public bool $priorSummaryPresent,

        /** Structural failure reason when preparation is not ready. */
        public ?string $failureReason,
    ) {
    }

    /**
     * Preparation is ready — compaction can proceed.
     *
     * @param list<AgentMessage> $messagesToSummarize
     * @param list<AgentMessage> $retainedTailMessages
     */
    public static function ready(
        array $messagesToSummarize,
        array $retainedTailMessages,
        int $tokenEstimateBefore,
        int $messagesCompacted,
        int $messagesRetained,
        int $firstRetainedIndex,
        bool $priorSummaryPresent,
    ): self {
        return new self(
            messagesToSummarize: $messagesToSummarize,
            retainedTailMessages: $retainedTailMessages,
            tokenEstimateBefore: $tokenEstimateBefore,
            messagesCompacted: $messagesCompacted,
            messagesRetained: $messagesRetained,
            firstRetainedIndex: $firstRetainedIndex,
            priorSummaryPresent: $priorSummaryPresent,
            failureReason: null,
        );
    }

    /**
     * Preparation cannot proceed for a structural reason.
     *
     * Maps to CompactionSkipReasonEnum values from the CodingAgent layer:
     * 'too_few_messages', 'below_keep_recent_tokens', 'no_boundary',
     * 'no_safe_boundary'.
     */
    public static function failed(string $reason): self
    {
        return new self(
            messagesToSummarize: null,
            retainedTailMessages: null,
            tokenEstimateBefore: 0,
            messagesCompacted: 0,
            messagesRetained: 0,
            firstRetainedIndex: 0,
            priorSummaryPresent: false,
            failureReason: $reason,
        );
    }

    public function isReady(): bool
    {
        return null !== $this->messagesToSummarize;
    }
}
