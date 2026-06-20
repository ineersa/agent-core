<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Model;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;

/**
 * Typed options bag for model invocations.
 *
 * Extensible by new pipeline/feature paths (compaction, background
 * summarization) without changing the PlatformInterface signature.
 */
final readonly class ModelInvocationOptions
{
    public function __construct(
        public ?CancellationTokenInterface $cancelToken = null,

        /**
         * When explicitly false, tools are disabled for this invocation
         * regardless of toolbox or ToolSetResolver configuration.  When
         * true or null, normal tool-resolution behaviour applies.
         */
        public ?bool $toolsEnabled = null,

        /**
         * Thinking/reasoning level override for this invocation.
         * When non-null, the value is forwarded to the platform as
         * the thinking_level option.  Typical values: off, minimal,
         * low, medium, high, xhigh.  When null, the session/model
         * default thinking level is used.
         */
        public ?string $thinkingLevel = null,

        /**
         * When false, the LlmStreamObserver is suppressed for this
         * invocation — no onStreamStart/onDelta/onStreamEnd/onStreamError
         * notifications are emitted.  This is used for compaction and
         * other non-interactive summarization calls where stream deltas
         * have no consumer.  Defaults to true (normal behaviour).
         */
        public bool $streamObserverEnabled = true,
    ) {
    }
}
