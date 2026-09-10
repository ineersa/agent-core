<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\AgentCore\Contract\Hook\LlmRequestRetryObserverInterface;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;

/**
 * Bridges application-level LLM retries to a transient runtime event.
 *
 * Emits seq=0 events so the TUI can show retry progress without durable
 * transcript history.
 */
final class LlmRequestRetryDispatchObserver implements LlmRequestRetryObserverInterface
{
    public function __construct(
        private readonly RuntimeEventSinkInterface $sink,
        private readonly ?RuntimeEventSinkInterface $stdoutSink = null,
    ) {
    }

    public function onRequestRetry(string $runId, ?string $stepId, array $retry): void
    {
        $payload = [
            'attempt' => $retry['attempt'],
            'max_attempts' => $retry['max_attempts'],
            'delay_ms' => $retry['delay_ms'],
            'reason' => $retry['reason'],
            'error_category' => $retry['error_category'],
            'error_type' => $retry['error_type'],
        ];
        if (null !== $stepId) {
            $payload['step_id'] = $stepId;
        }

        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::LlmRequestRetrying->value,
            runId: $runId,
            seq: 0,
            payload: $payload,
        );

        if ($this->stdoutSink instanceof StdoutRuntimeEventSink && $this->stdoutSink->isPipe()) {
            $this->stdoutSink->emit($event);

            return;
        }

        $this->sink->emit($event);
    }
}
