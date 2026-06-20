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
         * Additional model/provider/platform options forwarded to the
         * platform uninterpreted.  Keys like 'thinking_level',
         * 'reasoning_effort', etc. are passed through without
         * AgentCore knowledge of their semantics.
         *
         * Core-controlled flags (toolsEnabled, streamObserverEnabled)
         * are NOT part of this bag — they are applied after extraOptions
         * and always win over any key that appears here.
         *
         * @var array<string, mixed>
         */
        public array $extraOptions = [],

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
