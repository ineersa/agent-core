<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\LlmRequestRetryDispatchObserver;
use PHPUnit\Framework\TestCase;

final class LlmRequestRetryDispatchObserverTest extends TestCase
{
    public function testEmitsTransientRetryEventWithBudgetPayload(): void
    {
        $sink = new InMemoryRuntimeEventSink();
        $observer = new LlmRequestRetryDispatchObserver($sink);

        $observer->onRequestRetry('run-1', 'step-9', [
            'attempt' => 2,
            'max_attempts' => 5,
            'delay_ms' => 1000,
            'reason' => 'LLM provider request timed out.',
            'error_category' => 'timeout',
            'error_type' => 'Symfony\\Component\\HttpClient\\Exception\\TimeoutException',
        ]);

        $events = iterator_to_array($sink->drain('run-1'));
        $this->assertCount(1, $events);
        $this->assertSame(RuntimeEventTypeEnum::LlmRequestRetrying->value, $events[0]->type);
        $this->assertSame(0, $events[0]->seq);
        $this->assertSame('run-1', $events[0]->runId);
        $this->assertSame(2, $events[0]->payload['attempt']);
        $this->assertSame(5, $events[0]->payload['max_attempts']);
        $this->assertSame(1000, $events[0]->payload['delay_ms']);
        $this->assertSame('step-9', $events[0]->payload['step_id']);
    }
}
