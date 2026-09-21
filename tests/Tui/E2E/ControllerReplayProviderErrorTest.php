<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\RunLifecycleProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\ControllerReplayE2eTestCase;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Provider 429 to controller events to mounted TUI proof without a second TTY.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayProviderErrorTest extends ControllerReplayE2eTestCase
{
    private const string PROMPT = '[controller-replay:provider-error] exhaust a rate limit response';
    private const string SENTINEL = 'DO_NOT_LEAK_PROVIDER_BODY';

    public function testRateLimitExhaustionRendersActualRuntimeFailureEvent(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $commandId = 'cmd_provider_error_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $commandId,
            'type' => 'start_run',
            'payload' => ['prompt' => self::PROMPT],
        ]);

        $events = $this->collectEventsUntil('run.failed', 8.0);
        $byType = $this->indexByType($events);
        $diagnostics = $this->collectDiagnostics($events);
        $this->assertStartRunAcked($events, $commandId);
        $this->assertArrayHasKey('assistant.message_failed', $byType, $diagnostics);
        $this->assertArrayHasKey('run.failed', $byType, $diagnostics);
        $this->assertArrayNotHasKey('run.completed', $byType, $diagnostics);

        $failure = $byType['assistant.message_failed'][0]['payload'] ?? [];
        $this->assertFalse($failure['retryable'] ?? true, $diagnostics);
        $this->assertSame('rate_limit', $failure['error_category'] ?? null, $diagnostics);
        $this->assertSame(429, $failure['http_status_code'] ?? null, $diagnostics);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new RunLifecycleProjectionSubscriber());
        $state = new TranscriptProjectionState();
        $projector = new TranscriptProjector($dispatcher, $state);
        foreach ($events as $event) {
            $type = $event['type'] ?? null;
            if (!\is_string($type)) {
                continue;
            }
            $projector->accept(new RuntimeEvent(
                type: $type,
                runId: (string) ($event['runId'] ?? ''),
                seq: (int) ($event['seq'] ?? 0),
                payload: \is_array($event['payload'] ?? null) ? $event['payload'] : [],
            ));
        }

        $harness = new VirtualTuiHarness(columns: 160, rows: 20, sessionId: $this->sessionId, palette: new ThemePalette('errors', [
            ThemeColorEnum::Error->value => 'red',
        ]));
        $harness->screen()->setTranscriptBlocks($state->blocks());
        $ansi = $harness->ansiOutput();
        $plain = AnsiUtils::stripAnsiCodes($ansi);

        $this->assertStringContainsString('✕ Run failed', $plain);
        $this->assertStringContainsString('HTTP 429', $plain);
        $this->assertStringContainsString(self::SENTINEL, $plain);
        $this->assertStringContainsString('after retries were exhausted', $plain);
        $this->assertMatchesRegularExpression('/\x1b\[31m(?:\x1b\[[0-9;]*m)*\s+✕ Run failed/', $ansi);
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-replay-provider-error';
    }

    protected function extraSettingsYaml(): string
    {
        return <<<'YAML'
ai:
    http:
        max_retries: 0
        base_delay_ms: 0
        max_delay_ms: 0
YAML;
    }

    protected function replayFixtures(): array
    {
        return [[
            '$schema' => 'LLM Replay Fixture v1 - terminal provider rate limit',
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'recorded_at' => '2026-09-20T00:00:00Z',
            'recording_source' => 'synthetic',
            'http_status' => 429,
            'response_headers' => ['Content-Type' => 'application/json', 'Retry-After' => '30'],
            'response_body' => json_encode(['error' => [
                'message' => 'rate limit raw sentinel '.self::SENTINEL,
                'code' => 'rate_limit_exceeded',
                'type' => 'rate_limit_error',
            ]], \JSON_THROW_ON_ERROR),
        ]];
    }
}
