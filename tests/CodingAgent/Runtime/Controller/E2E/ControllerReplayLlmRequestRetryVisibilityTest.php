<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deterministic controller-replay proof that application LLM retries emit a
 * transient llm.request_retrying stdout event before recovery, without durable
 * transcript persistence and without depending on streamObserverEnabled for
 * compaction-style summary streams.
 *
 * Fixture sequence:
 *  1. HTTP 429 rate-limit error (retryable)
 *  2. Successful assistant text response
 *
 * Settings use ai.http.max_retries=5 with base_delay_ms=0 so the retry budget
 * is exercised without sleeping under the 10s case ceiling.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayLlmRequestRetryVisibilityTest extends ControllerReplayE2eTestCase
{
    private const string PROMPT = '[controller-replay:llm-request-retry] recover after one rate limit';
    private const string ASSISTANT = 'retry recovered';

    public function testRetryEventIsVisibleOnStdoutThenRunRecovers(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_retry_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => self::PROMPT,
            ],
        ]);

        $events = $this->collectEventsUntil('run.completed', 8.0);
        $byType = $this->indexByType($events);

        $this->assertStartRunAcked($events, $startCmdId);
        $this->assertArrayHasKey('run.started', $byType, $this->collectDiagnostics($events));
        $this->assertArrayHasKey(
            'llm.request_retrying',
            $byType,
            'Application retry must emit llm.request_retrying. '.$this->collectDiagnostics($events),
        );

        $retry = $byType['llm.request_retrying'][0];
        $this->assertSame(0, $retry['seq'] ?? null, 'retry events are transient (seq=0)');
        $this->assertSame(1, $retry['payload']['attempt'] ?? null, $this->collectDiagnostics($events));
        $this->assertSame(5, $retry['payload']['max_attempts'] ?? null, $this->collectDiagnostics($events));
        $this->assertSame(0, $retry['payload']['delay_ms'] ?? null, $this->collectDiagnostics($events));
        $this->assertSame(
            'LLM provider rate limit interrupted the request.',
            $retry['payload']['reason'] ?? null,
            $this->collectDiagnostics($events),
        );
        $this->assertSame('rate_limit', $retry['payload']['error_category'] ?? null, $this->collectDiagnostics($events));

        $this->assertArrayHasKey('run.completed', $byType, $this->collectDiagnostics($events));
        $this->assertArrayNotHasKey('run.failed', $byType, $this->collectDiagnostics($events));

        $this->assertArrayHasKey('assistant.message_completed', $byType, $this->collectDiagnostics($events));

        $runId = (string) ($byType['run.started'][0]['runId']
            ?? $byType['run.started'][0]['payload']['runId']
            ?? '');
        $this->assertNotEmpty($runId);
        $eventsJsonl = $this->tempDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
        $this->assertFileExists($eventsJsonl);
        $durable = (string) file_get_contents($eventsJsonl);
        $this->assertStringNotContainsString(
            'llm.request_retrying',
            $durable,
            'Retry progress must stay out of durable session events.jsonl',
        );
        $this->assertStringContainsString(self::ASSISTANT, $durable, 'Recovered assistant text must be durable');
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-replay-llm-retry';
    }

    protected function extraSettingsYaml(): string
    {
        return <<<'YAML'
ai:
    http:
        max_retries: 5
        base_delay_ms: 0
        max_delay_ms: 0
YAML;
    }

    protected function replayFixtures(): array
    {
        return [
            [
                '$schema' => 'LLM Replay Fixture v1 — controller retry visibility (rate limit then recover)',
                'model' => 'llama_cpp_test/test',
                'provider_id' => 'llama_cpp_test',
                'reasoning' => 'off',
                'recorded_at' => '2026-09-10T00:00:00Z',
                'recording_source' => 'synthetic',
                'http_status' => 429,
                'response_headers' => [
                    'Content-Type' => 'application/json',
                ],
                'response_body' => json_encode([
                    'error' => [
                        'message' => 'rate limit raw sentinel DO_NOT_LEAK_PROVIDER_BODY',
                        'code' => 'rate_limit_exceeded',
                        'type' => 'rate_limit_error',
                    ],
                ], \JSON_THROW_ON_ERROR),
            ],
            [
                '$schema' => 'LLM Replay Fixture v1 — controller retry visibility recovery',
                'model' => 'llama_cpp_test/test',
                'provider_id' => 'llama_cpp_test',
                'reasoning' => 'off',
                'recorded_at' => '2026-09-10T00:00:00Z',
                'recording_source' => 'synthetic',
                'input' => [
                    'messages' => [
                        ['role' => 'user', 'content' => self::PROMPT],
                    ],
                ],
                'deltas' => [
                    ['type' => 'text', 'content' => self::ASSISTANT],
                ],
                'usage' => [
                    'input_tokens' => 12,
                    'output_tokens' => 3,
                ],
                'stop_reason' => 'stop',
            ],
        ];
    }
}
