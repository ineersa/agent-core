<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * TUI E2E: cancel during bash background-prompt path (issue #205).
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class BashBackgroundCancelE2eTest extends TestCase
{
    use BashBackgroundE2eTestSupport;

    protected function setUp(): void
    {
        $this->setUpBashBackgroundE2e('tui-bg-cancel', 'tui-e2e-bash-bg-cancel');
    }

    protected function tearDown(): void
    {
        $this->tearDownBashBackgroundE2e();
    }

    public function testBashBackgroundPromptPathCanBeCancelledWithoutLeaks(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommandWithFixtures('tui-tool-call-bash-sleep8.json'),
            prefix: 'bash-bg-cancel',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->prepareEditorForUserPrompt($this->tmux, $pane);

            $this->tmux->sendLiteral($pane, 'Run sleep 8');
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForHistoryContains($pane, 'Running', 20.0);
            $this->waitForBashBackgroundPrompt($this->tmux, $pane);

            $this->tmux->sendKey($pane, 'Escape');
            usleep(200_000);
            $this->tmux->sendKey($pane, 'Escape');

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Cancelling')
                    || str_contains($cap, 'cancelling')
                    || str_contains($cap, 'Cancelled')
                    || str_contains($cap, '● idle'),
                timeout: 12.0,
                message: 'TUI did not reach cancelling/cancelled/idle after Escape',
                history: 2000,
            );

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Cancelled')
                    || str_contains($cap, '● idle'),
                timeout: 10.0,
                message: 'TUI did not settle to cancelled or idle',
                history: 2000,
            );

            $this->tmux->sendKey($pane, 'C-d');
            $this->assertNoLeakedWorkersForThisTestWithRetry();
        } catch (\Throwable $e) {
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }
}
