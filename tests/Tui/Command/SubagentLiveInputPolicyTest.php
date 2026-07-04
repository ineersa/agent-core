<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Command;

use Ineersa\Tui\Command\SubagentLiveInputPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SubagentLiveInputPolicyTest extends TestCase
{
    private SubagentLiveInputPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new SubagentLiveInputPolicy();
    }

    #[Test]
    public function blocksShellAndUnrelatedSlashCommandsInLiveView(): void
    {
        $this->assertTrue($this->policy->shouldBlockInLiveView('!pwd'));
        $this->assertTrue($this->policy->shouldBlockInLiveView('!!pwd'));
        $this->assertTrue($this->policy->shouldBlockInLiveView('/new'));
        $this->assertTrue($this->policy->shouldBlockInLiveView('/resume abc'));
        $this->assertTrue($this->policy->shouldBlockInLiveView('/tasks'));
        $this->assertFalse($this->policy->shouldBlockInLiveView('/agents-main'));
        $this->assertFalse($this->policy->shouldBlockInLiveView('/main'));
        $this->assertFalse($this->policy->shouldBlockInLiveView('/agents-live'));
        $this->assertFalse($this->policy->shouldBlockInLiveView('steer this child'));
    }

    #[Test]
    public function childUserCommandTypeUsesActivity(): void
    {
        $this->assertSame('steer', $this->policy->childUserCommandType(true));
        $this->assertSame('follow_up', $this->policy->childUserCommandType(false));
    }

    #[Test]
    public function dispatchConfirmationMessages(): void
    {
        $this->assertStringContainsString('steer', $this->policy->dispatchConfirmationMessage('steer', 'scout'));
        $this->assertStringContainsString('follow_up', $this->policy->dispatchConfirmationMessage('follow_up', 'scout'));
    }

    #[Test]
    public function allowedLiveViewNavigationSlashCommands(): void
    {
        $this->assertTrue($this->policy->isAllowedLiveViewNavigationSlash('/agents-main'));
        $this->assertTrue($this->policy->isAllowedLiveViewNavigationSlash('/main'));
        $this->assertTrue($this->policy->isAllowedLiveViewNavigationSlash('/agents-live'));
        $this->assertFalse($this->policy->isAllowedLiveViewNavigationSlash('/new'));
        $this->assertFalse($this->policy->isAllowedLiveViewNavigationSlash('steer child'));
    }

    #[Test]
    public function terminalChildInputBlockedMessageMentionsAgentsMain(): void
    {
        $this->assertStringContainsString('/agents-main', $this->policy->terminalChildInputBlockedMessage());
        $this->assertStringContainsString('finished', strtolower($this->policy->terminalChildInputBlockedMessage()));
    }
}
