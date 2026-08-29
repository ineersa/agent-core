<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Event;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\Event\RunOperationalProjectionControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RunOperationalProjectionControllerSessionLifecycleListener::class)]
final class RunOperationalProjectionControllerSessionLifecycleListenerTest extends IsolatedKernelTestCase
{
    private RunOperationalProjectionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get('test.run_operational_projection_repository');
    }

    public function testStartingClearsOnlyTheOwnedProjectionBeforeConsumersLaunch(): void
    {
        $this->repository->replace(new RunState('session-a', RunStatus::Running));
        $this->repository->replace(new RunState('session-b', RunStatus::Running));

        (new RunOperationalProjectionControllerSessionLifecycleListener($this->repository))
            ->onSessionStarting(new ControllerSessionStartingEvent('session-a'));

        $this->assertNull($this->repository->findOperationalStatus('session-a'));
        $this->assertSame(RunStatus::Running, $this->repository->findOperationalStatus('session-b')?->status);
    }
}
