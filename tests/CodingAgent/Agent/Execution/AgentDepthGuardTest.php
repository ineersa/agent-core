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
        putenv('HATFIELD_AGENT_CHILD');
        putenv('HATFIELD_AGENT_DEPTH');
        putenv('HATFIELD_AGENT_MAX_DEPTH');
        putenv('HATFIELD_AGENTS_DISABLED');
    }

    public function testCurrentDepthZeroWhenNotChild(): void
    {
        $guard = new AgentDepthGuard();
        self::assertSame(0, $guard->currentDepth());
    }

    public function testCurrentDepthReadsEnvWhenChild(): void
    {
        putenv('HATFIELD_AGENT_CHILD=1');
        putenv('HATFIELD_AGENT_DEPTH=3');

        $guard = new AgentDepthGuard();
        self::assertSame(3, $guard->currentDepth());
    }

    public function testCurrentDepthDefaultsToOneWhenChildButNoDepth(): void
    {
        putenv('HATFIELD_AGENT_CHILD=1');
        // No HATFIELD_AGENT_DEPTH set.

        $guard = new AgentDepthGuard();
        self::assertSame(1, $guard->currentDepth());
    }

    public function testCheckAllowedReturnsNullWhenUnderMaxDepth(): void
    {
        $guard = new AgentDepthGuard();
        $result = $guard->checkAllowed(currentDepth: 0, agentMaxDepth: 2);
        self::assertNull($result);
    }

    public function testCheckAllowedBlocksWhenAtMaxDepth(): void
    {
        $guard = new AgentDepthGuard();
        $result = $guard->checkAllowed(currentDepth: 1, agentMaxDepth: 1);
        self::assertNotNull($result);
        self::assertStringContainsString('depth 1 meets or exceeds max depth 1', $result);
    }

    public function testCheckAllowedBlocksWhenExceedsMaxDepth(): void
    {
        $guard = new AgentDepthGuard();
        $result = $guard->checkAllowed(currentDepth: 2, agentMaxDepth: 1);
        self::assertNotNull($result);
        self::assertStringContainsString('depth 2 meets or exceeds max depth 1', $result);
    }

    public function testCheckAllowedBlocksWhenGloballyDisabled(): void
    {
        putenv('HATFIELD_AGENTS_DISABLED=1');

        $guard = new AgentDepthGuard();
        $result = $guard->checkAllowed(currentDepth: 0, agentMaxDepth: 5);
        self::assertNotNull($result);
        self::assertStringContainsString('globally disabled', $result);
    }

    public function testCheckAllowedRespectsGlobalMaxDepthEnv(): void
    {
        putenv('HATFIELD_AGENT_MAX_DEPTH=1');

        $guard = new AgentDepthGuard();
        // Agent allows 5, but global env caps at 1.
        $result = $guard->checkAllowed(currentDepth: 1, agentMaxDepth: 5);
        self::assertNotNull($result);
    }

    public function testChildDepthIncrements(): void
    {
        $guard = new AgentDepthGuard();
        self::assertSame(1, $guard->childDepth(0));
        self::assertSame(2, $guard->childDepth(1));
    }

    public function testChildEnvSetsExpectedVars(): void
    {
        $guard = new AgentDepthGuard();
        $env = $guard->childEnv(childDepth: 2, maxDepth: 3, agentsDisabled: false);

        self::assertSame('1', $env['HATFIELD_AGENT_CHILD']);
        self::assertSame('2', $env['HATFIELD_AGENT_DEPTH']);
        self::assertSame('3', $env['HATFIELD_AGENT_MAX_DEPTH']);
        self::assertArrayNotHasKey('HATFIELD_AGENTS_DISABLED', $env);
    }

    public function testChildEnvIncludesAgentsDisabledWhenTrue(): void
    {
        $guard = new AgentDepthGuard();
        $env = $guard->childEnv(childDepth: 1, maxDepth: 1, agentsDisabled: true);

        self::assertSame('1', $env['HATFIELD_AGENTS_DISABLED']);
    }
}
