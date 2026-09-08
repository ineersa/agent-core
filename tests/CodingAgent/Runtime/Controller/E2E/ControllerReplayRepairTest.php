<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/** Real controller wiring must execute repair without relying on TUI transport env. */
#[Group('controller-replay')]
final class ControllerReplayRepairTest extends ControllerReplayE2eTestCase
{
    public function testRepairReturnsExplicitRefusalFromOwningRuntime(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
        $this->writeCommand([
            'v' => 1,
            'id' => 'repair-empty-session',
            'type' => 'repair',
            'runId' => $this->sessionId,
        ]);

        $events = $this->collectEventsUntil('session.repair.completed', 5.0);
        $byType = $this->indexByType($events);
        $this->assertArrayHasKey('session.repair.completed', $byType, $this->collectDiagnostics($events));
        $result = $byType['session.repair.completed'][0];
        $this->assertSame($this->sessionId, $result['runId']);
        $this->assertSame('repair-empty-session', $result['payload']['commandId']);
        $this->assertSame('completed', $result['payload']['status']);
        $this->assertSame('no_events', $result['payload']['refusal_reason']);
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-repair';
    }

    protected function replayFixtures(): array
    {
        return [];
    }
}
