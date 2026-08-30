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
        unset($_ENV['HATFIELD_AGENTS_DISABLED'], $_SERVER['HATFIELD_AGENTS_DISABLED']);
    }

    public function testCheckLaunchAllowedWhenEnabled(): void
    {
        $guard = new AgentDepthGuard();
        $this->assertNull($guard->checkLaunchAllowed());
    }

    public function testCheckLaunchBlockedWhenGloballyDisabled(): void
    {
        putenv('HATFIELD_AGENTS_DISABLED=1');
        $_ENV['HATFIELD_AGENTS_DISABLED'] = '1';
        $_SERVER['HATFIELD_AGENTS_DISABLED'] = '1';

        $guard = new AgentDepthGuard();
        $result = $guard->checkLaunchAllowed();
        $this->assertNotNull($result);
        $this->assertStringContainsString('globally disabled', $result);
    }
}
