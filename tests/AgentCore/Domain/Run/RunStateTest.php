<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Run;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use PHPUnit\Framework\TestCase;

final class RunStateTest extends TestCase
{
    public function testQueuedFactoryCreatesQueuedStateWithDefaults(): void
    {
        $state = RunState::queued('run-test-1');

        $this->assertSame('run-test-1', $state->runId);
        $this->assertSame(RunStatus::Queued, $state->status);
        $this->assertSame(0, $state->version);
        $this->assertSame(0, $state->turnNo);
        $this->assertSame(0, $state->lastSeq);
        $this->assertFalse($state->isStreaming);
        $this->assertNull($state->streamingMessage);
        $this->assertSame([], $state->pendingToolCalls);
        $this->assertNull($state->errorMessage);
        $this->assertSame([], $state->messages);
        $this->assertNull($state->activeStepId);
        $this->assertFalse($state->retryableFailure);
    }
}
