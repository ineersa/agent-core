<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Replay;

use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Session\Replay\SessionReplayIntegrityVerifier;
use PHPUnit\Framework\TestCase;

final class SessionReplayIntegrityVerifierTest extends TestCase
{
    public function testIntegrityVerificationReadsCanonicalEvents(): void
    {
        $events = new InMemoryEventStore();
        $events->seed(new RunEvent(runId: 'run-integrity', seq: 1, turnNo: 0, type: 'run_started', payload: []));
        $events->seed(new RunEvent(runId: 'run-integrity', seq: 3, turnNo: 1, type: 'turn_advanced', payload: []));
        $service = new SessionReplayIntegrityVerifier($events, new ReplayEventPreparer());

        $integrity = $service->verifyIntegrity('run-integrity');

        $this->assertSame([2], $integrity->missingSequences);
        $this->assertSame(1, $events->allForCalls);
    }
}
