<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Replay-backed proof: cancel an in-flight bash tool, then follow_up resumes the run.
 *
 * Catches session-2 failure where the run stayed cancelled and follow-up was rejected.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayBashCancelFollowUpTest extends ControllerReplayE2eTestCase
{
    private const string FOLLOW_UP_SENTINEL = 'FOLLOWUP_AFTER_BASH_CANCEL_OK';

    public function testBashCancelThenFollowUpProducesAssistantResponse(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Run bash sleep 8 once. Do not call any other tool.',
            ],
        ]);

        $phase1 = $this->collectEventsUntil('tool_execution.started', 8.0);
        $p1 = $this->indexByType($phase1);
        $this->assertStartRunAcked($phase1, $startCmdId);
        $this->assertArrayHasKey('tool_execution.started', $p1, $this->collectDiagnostics($phase1));
        $this->assertSame(
            'bash',
            $p1['tool_execution.started'][0]['payload']['tool_name'] ?? null,
            $this->collectDiagnostics($phase1),
        );

        $runStarted = $p1['run.started'][0] ?? null;
        $this->assertIsArray($runStarted);
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId);

        $cancelCmdId = 'cmd_cancel_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $cancelCmdId,
            'type' => 'cancel',
            'runId' => $this->runId,
        ]);

        $cancelPhase = $this->collectEventsUntil('run.cancelled', 12.0);
        $cancelByType = $this->indexByType($cancelPhase);
        $this->assertTrue(
            $this->foundAck($cancelPhase, $cancelCmdId),
            'Expected command.ack for cancel. '.$this->collectDiagnostics($cancelPhase),
        );
        $this->assertArrayHasKey(
            'run.cancelled',
            $cancelByType,
            'Bash tool cancel must terminalize run as run.cancelled. '.$this->collectDiagnostics($cancelPhase),
        );

        $followUpCmdId = 'cmd_fu_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $followUpCmdId,
            'type' => 'follow_up',
            'runId' => $this->runId,
            'payload' => ['text' => self::FOLLOW_UP_SENTINEL],
        ]);

        $followUpPhase = $this->collectEventsUntil('run.completed', 15.0);
        $fuByType = $this->indexByType($followUpPhase);

        $this->assertTrue(
            $this->foundAck($followUpPhase, $followUpCmdId),
            'follow_up after cancel must be accepted (command.ack). '.$this->collectDiagnostics($followUpPhase),
        );
        $this->assertArrayNotHasKey(
            'command.rejected',
            $fuByType,
            'follow_up must not be rejected after cancellation. '.$this->collectDiagnostics($followUpPhase),
        );
        $this->assertArrayHasKey(
            'run.completed',
            $fuByType,
            'follow_up after cancel must complete a new turn. '.$this->collectDiagnostics($followUpPhase),
        );
        $this->assertTrue(
            $this->hasAssistantResponseEvidence($fuByType),
            'follow_up after cancel must produce assistant output. '.$this->collectDiagnostics($followUpPhase),
        );

        $textEvents = $fuByType['assistant.text_delta'] ?? $fuByType['assistant.message_completed'] ?? [];
        $joined = '';
        foreach ($textEvents as $ev) {
            $payload = $ev['payload'] ?? [];
            if (!\is_array($payload)) {
                continue;
            }
            $joined .= (string) ($payload['text'] ?? $payload['content'] ?? '');
        }
        $this->assertStringContainsString(
            self::FOLLOW_UP_SENTINEL,
            '' !== $joined ? $joined : json_encode($fuByType, \JSON_UNESCAPED_UNICODE),
            'Replay fixture sentinel must appear in assistant output after follow_up.',
        );
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-bash-cancel-fu';
    }

    /**
     * @return list<string>
     */
    protected function controllerExtraArgs(): array
    {
        return [];
    }

    protected function extraSettingsYaml(): string
    {
        return <<<'YAML'

tools:
    bash:
        background_prompt_threshold_seconds: 60
YAML;
    }

    protected function createIsolatedProjectDir(): void
    {
        parent::createIsolatedProjectDir();

        $path = $this->tempDir.'/.hatfield/settings.yaml';
        $settings = \Symfony\Component\Yaml\Yaml::parseFile($path);
        \PHPUnit\Framework\Assert::assertIsArray($settings);
        $settings['extensions']['settings']['safe_guard']['allow_command_patterns'] = ['^sleep\\b'];
        file_put_contents($path, \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4));
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        $bashFixturePath = \dirname(__DIR__, 4).'/Tui/E2E/fixtures/tui-tool-call-bash-sleep8.json';
        $bashFixture = json_decode(
            (string) file_get_contents($bashFixturePath),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        \PHPUnit\Framework\Assert::assertIsArray($bashFixture);

        $followUpFixture = [
            '$schema' => 'Synthetic controller replay — follow_up after bash cancel',
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'recorded_at' => '2026-06-25T00:00:00+00:00',
            'recording_source' => 'manual',
            'input' => [
                'messages' => [
                    ['role' => 'user', 'content' => self::FOLLOW_UP_SENTINEL],
                ],
            ],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 8, 'total_tokens' => 13],
            'stop_reason' => 'stop',
            'expected_text' => self::FOLLOW_UP_SENTINEL,
            'deltas' => [
                ['type' => 'text', 'content' => self::FOLLOW_UP_SENTINEL],
            ],
        ];

        return [$bashFixture, $followUpFixture];
    }

    protected function replayExtraEnv(): array
    {
        return [];
    }
}
