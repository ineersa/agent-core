<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Extensions;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension;
use Ineersa\CodingAgent\Extension\Builtin\Scheduling\SchedulingExtension;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class ExtensionsSetupFlowTest extends IsolatedKernelTestCase
{
    public function testFreshProjectOffersBuiltinsAndSupportedOptionalPackages(): void
    {
        $flow = self::getContainer()->get(ExtensionsSetupFlow::class);

        $this->assertSame([
            'safe_guard' => ['label' => 'SafeGuard (built in)', 'installed' => true, 'class' => SafeGuardExtension::class],
            'scheduling' => ['label' => 'Scheduling (built in)', 'installed' => true, 'class' => SchedulingExtension::class],
            'ineersa/hatfield-ext-task-workflow' => ['label' => 'Task Workflow', 'installed' => false, 'class' => null],
            'ineersa/hatfield-ext-observational-memory' => ['label' => 'Observational Memory', 'installed' => false, 'class' => null],
        ], $flow->packages());
    }

    public function testDisablingParentWithDefaultChildSelectionsIsRefused(): void
    {
        $flow = self::getContainer()->get(ExtensionsSetupFlow::class);
        $flow->select('safe_guard');
        $this->assertSame(['parent' => 'true', 'forks' => 'always_on', 'agents' => 'true'], $flow->members);
        $flow->members['parent'] = 'false';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Enable the parent extension before selecting it for forks or agents.');
        $flow->save([]);
    }
}
