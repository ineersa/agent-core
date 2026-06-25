<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * TUI E2E: accepting bash background prompt moves command to background and tracks it.
 *
 * Thesis: confirm overlay Yes sends boolean true through answer_tool_question, bash marks
 * the process backgrounded, and a follow-up bg_status list (replay second turn) sees the process.
 *
 * Confirm overlay lists Yes first; Enter selects yes without Down arrow.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class BashBackgroundAcceptE2eTest extends TestCase
{
    use BashBackgroundE2eTestSupport;

    private ?int $backgroundedPid = null;

    protected function setUp(): void
    {
        $this->setUpBashBackgroundE2e('tui-bg-accept', 'tui-e2e-bash-bg-accept');
    }

    protected function tearDown(): void
    {
        if (null !== $this->backgroundedPid && $this->backgroundedPid > 0 && \function_exists('posix_kill')) {
            @posix_kill($this->backgroundedPid, \SIGTERM);
        }
        $this->tearDownBashBackgroundE2e();
    }

    public function testAcceptingBashBackgroundPromptTracksProcessForBgStatus(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommandWithFixtures(
                'tui-tool-call-bash-sleep8.json',
                'tui-tool-call-bg-status-list.json',
            ),
            prefix: 'bash-bg-accept',
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

            // Yes is the first SelectList item for QuestionKind::Confirm.
            usleep(200_000);
            $this->tmux->sendKey($pane, 'Enter');
            usleep(200_000);

            $this->tmux->waitForCallback(
                $pane,
                function (string $cap): bool {
                    if (!str_contains($cap, 'moved to background')) {
                        return false;
                    }
                    if (preg_match('/PID:\s*(\d+)/', $cap, $m)) {
                        $this->backgroundedPid = (int) $m[1];
                    }

                    return str_contains($cap, 'bg_status');
                },
                timeout: 12.0,
                message: 'Bash did not return background notice with PID and bg_status guidance',
                history: 4000,
            );

            self::assertNotNull($this->backgroundedPid, 'Background notice should include a PID');
            self::assertGreaterThan(0, $this->backgroundedPid);

            // Second replay fixture serves the post-bash LLM turn (bg_status list).
            $this->tmux->waitForCallback(
                $pane,
                fn (string $cap): bool => null !== $this->backgroundedPid
                    && str_contains($cap, (string) $this->backgroundedPid)
                    && str_contains($cap, 'sleep 8'),
                timeout: 15.0,
                message: 'bg_status list did not show the backgrounded sleep 8 process',
                history: 6000,
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
