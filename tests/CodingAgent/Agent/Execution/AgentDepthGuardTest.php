<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\CodingAgent\Agent\Execution\AgentDepthGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentDepthGuard::class)]
final class AgentDepthGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('HATFIELD_AGENTS_DISABLED');
    }

    public function testCheckLaunchAllowedWhenParentIsNotChild(): void
    {
        $guard = new AgentDepthGuard();
        $this->assertNull($guard->checkLaunchAllowed(parentIsAgentChild: false));
    }

    public function testCheckLaunchBlockedWhenParentIsAgentChild(): void
    {
        $guard = new AgentDepthGuard();
        $result = $guard->checkLaunchAllowed(parentIsAgentChild: true);
        $this->assertNotNull($result);
        $this->assertStringContainsString('Nested subagent launches are not supported', $result);
    }

    public function testCheckLaunchBlockedWhenGloballyDisabled(): void
    {
        putenv('HATFIELD_AGENTS_DISABLED=1');

        $guard = new AgentDepthGuard();
        $result = $guard->checkLaunchAllowed(parentIsAgentChild: false);
        $this->assertNotNull($result);
        $this->assertStringContainsString('globally disabled', $result);
    }
}
