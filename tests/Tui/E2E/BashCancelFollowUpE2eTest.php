<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * TUI E2E: cancel running bash, then submit follow-up and observe assistant response.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class BashCancelFollowUpE2eTest extends TestCase
{
    use BashBackgroundE2eTestSupport;

    private const string FOLLOW_UP_PROMPT = 'Say exactly TUI_FOLLOWUP_AFTER_CANCEL_OK';
    private const string FOLLOW_UP_SENTINEL = 'TUI_FOLLOWUP_AFTER_CANCEL_OK';

    protected function setUp(): void
    {
        $this->setUpBashBackgroundE2e('tui-bg-cancel-fu', 'tui-e2e-bash-cancel-fu');
    }

    protected function tearDown(): void
    {
        $this->tearDownBashBackgroundE2e();
    }

    public function testFollowUpAfterBashCancelShowsAssistantResponse(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommandWithFixtures(
                'tui-tool-call-bash-sleep8.json',
                'tui-followup-after-cancel-text.json',
            ),
            prefix: 'bash-cancel-fu',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->prepareEditorForUserPrompt($this->tmux, $pane);

            $this->tmux->sendLiteral($pane, 'Run sleep 8');
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForHistoryContains($pane, 'Running', 20.0);

            $this->tmux->sendKey($pane, 'Escape');
            usleep(200_000);
            $this->tmux->sendKey($pane, 'Escape');

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Cancelled')
                    || str_contains($cap, '● idle'),
                timeout: 12.0,
                message: 'TUI did not settle to cancelled or idle after bash cancel',
                history: 2000,
            );

            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);
            $this->tmux->sendLiteral($pane, self::FOLLOW_UP_PROMPT);
            $this->tmux->sendKey($pane, 'Enter');

            $sentinel = self::FOLLOW_UP_SENTINEL;
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, $sentinel),
                timeout: 20.0,
                message: 'Assistant response after follow-up did not appear — run may still be dead after cancel',
                history: 4000,
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
