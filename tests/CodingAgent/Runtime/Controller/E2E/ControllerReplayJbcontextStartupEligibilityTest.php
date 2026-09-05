<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use PHPUnit\Framework\Attributes\Group;

/**
 * Controller-subprocess proof that interactive session start loads the public
 * session-start hook, dispatches one eligibility job on the real extension_agent
 * transport, and the worker reaches a terminal disabled mode before any turn.
 *
 * Isolated project has no `.idea`, so the worker disables without invoking
 * jbcontext CLI / index. Asserts positive status.json contents, not absence.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayJbcontextStartupEligibilityTest extends ControllerReplayE2eTestCase
{
    private const string MISSING_IDEA_STATUS = 'jbcontext disabled: project has no .idea directory. Open the project in JetBrains IDE and run jbcontext index manually before enabling search.';

    public function testControllerStartupDisablesEligibilityWithoutIdeaBeforeAnyTurn(): void
    {
        $this->assertDirectoryDoesNotExist($this->tempDir.'/.idea');

        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $statusPath = JbcontextPaths::fromProjectRoot($this->tempDir)
            ->sessionStatusPath($this->sessionId);

        $deadline = microtime(true) + 8.0;
        $mode = null;
        $statusText = null;
        $eligibilityStarted = false;
        while (microtime(true) < $deadline) {
            $this->assertRunning('waiting for jbcontext terminal status');
            if (is_file($statusPath)) {
                $state = JbcontextStatusStore::forSession(
                    JbcontextPaths::fromProjectRoot($this->tempDir),
                    $this->sessionId,
                )->read();
                $mode = $state->mode;
                $statusText = $state->statusText;
                $eligibilityStarted = $state->eligibilityStarted;
                if (JbcontextSessionModeEnum::Disabled === $mode
                    && self::MISSING_IDEA_STATUS === $statusText
                    && true === $eligibilityStarted
                ) {
                    break;
                }
            }
            usleep(10_000);
        }

        $this->assertFileExists(
            $statusPath,
            'Controller session-start must create session status before any turn. '
            .$this->collectDiagnostics([]),
        );
        $this->assertTrue(
            $eligibilityStarted,
            'eligibility_started must be claimed by the production session-start hook. '
            .$this->collectDiagnostics([]),
        );
        $this->assertSame(
            JbcontextSessionModeEnum::Disabled,
            $mode,
            'extension_agent worker must reach Disabled without .idea. '
            .$this->collectDiagnostics([]),
        );
        $this->assertSame(
            self::MISSING_IDEA_STATUS,
            $statusText,
            'Worker must disable for missing .idea without indexing. '
            .$this->collectDiagnostics([]),
        );

        $this->assertDirectoryDoesNotExist($this->tempDir.'/.idea');
        $this->assertDirectoryDoesNotExist($this->tempDir.'/.hatfield/skills/jbcontext-semantic-search');
        $this->assertFileDoesNotExist($this->tempDir.'/.hatfield/agents/scout.md');
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-replay-jbcontext-startup';
    }

    protected function modelConfig(): array
    {
        return [
            'input' => ['text'],
            'tool_calling' => false,
        ];
    }

    protected function extraSettingsYaml(): string
    {
        return <<<YAML
extensions:
    enabled:
        - Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension
        - Ineersa\\HatfieldExt\\Jbcontext\\JbcontextExtension
YAML;
    }

    /**
     * Unused: this case never starts a run. One fixture keeps the abstract
     * contract satisfied if a future change accidentally requests an LLM turn.
     *
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        return [[
            '$schema' => 'Synthetic controller replay — unused for jbcontext startup proof',
            'fixture_source' => 'synthetic',
            'synthetic_reason' => 'Startup eligibility completes before any turn; fixture is a fail-loud absorber only.',
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'stop_reason' => 'stop',
            'deltas' => [
                ['type' => 'text', 'content' => 'unused'],
            ],
        ]];
    }
}
