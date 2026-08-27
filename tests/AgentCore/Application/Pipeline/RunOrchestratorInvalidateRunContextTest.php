<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\RunOrchestrator;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Domain\Message\InvalidateRunContext;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;

final class RunOrchestratorInvalidateRunContextTest extends PerMethodIsolatedKernelTestCase
{
    public function testInvalidationOnlyClearsActiveContext(): void
    {
        $activeContext = $this->createMock(ActiveRunContextInterface::class);
        $activeContext->expects($this->once())->method('invalidate')->with('run-invalidate');
        $activeContext->expects($this->never())->method('stateFor');
        $activeContext->expects($this->never())->method('remember');
        $activeContext->expects($this->never())->method('clear');
        self::getContainer()->set(ActiveRunContextInterface::class, $activeContext);

        /** @var RunOrchestrator $orchestrator */
        $orchestrator = self::getContainer()->get(RunOrchestrator::class);
        $orchestrator->onInvalidateRunContext(new InvalidateRunContext('run-invalidate'));
    }
}
