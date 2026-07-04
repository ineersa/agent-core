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
        self::assertTrue($this->policy->shouldBlockInLiveView('!pwd'));
        self::assertTrue($this->policy->shouldBlockInLiveView('!!pwd'));
        self::assertTrue($this->policy->shouldBlockInLiveView('/new'));
        self::assertTrue($this->policy->shouldBlockInLiveView('/resume abc'));
        self::assertTrue($this->policy->shouldBlockInLiveView('/tasks'));
        self::assertFalse($this->policy->shouldBlockInLiveView('/agents-main'));
        self::assertFalse($this->policy->shouldBlockInLiveView('/main'));
        self::assertFalse($this->policy->shouldBlockInLiveView('/agents-live'));
        self::assertFalse($this->policy->shouldBlockInLiveView('steer this child'));
    }

    #[Test]
    public function childUserCommandTypeUsesActivity(): void
    {
        self::assertSame('steer', $this->policy->childUserCommandType(true));
        self::assertSame('follow_up', $this->policy->childUserCommandType(false));
    }

    #[Test]
    public function dispatchConfirmationMessages(): void
    {
        self::assertStringContainsString('steer', $this->policy->dispatchConfirmationMessage('steer', 'scout'));
        self::assertStringContainsString('follow_up', $this->policy->dispatchConfirmationMessage('follow_up', 'scout'));
    }

    #[Test]
    public function terminalChildInputBlockedMessageMentionsAgentsMain(): void
    {
        self::assertStringContainsString('/agents-main', $this->policy->terminalChildInputBlockedMessage());
        self::assertStringContainsString('finished', strtolower($this->policy->terminalChildInputBlockedMessage()));
    }
}
