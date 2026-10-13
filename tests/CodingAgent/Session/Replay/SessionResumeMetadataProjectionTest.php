<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Replay;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Session\Replay\SessionResumeMetadataProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionResumeMetadataProjectionTest extends TestCase
{
    public function testHistoricalQueuesDoNotAccumulateAndContinuationKeepsCurrentPendingInput(): void
    {
        $projection = new SessionResumeMetadataProjection();
        for ($seq = 1; $seq <= 4000; ++$seq) {
            $key = 'completed-'.$seq;
            $projection->observe(new RunEvent('42', $seq, 0, 'agent_command_queued'), new RuntimeEvent('user.message_queued', '42', $seq, ['idempotency_key' => $key, 'text' => 'Old input']), false);
        }
        $this->assertSame([], $projection->toArray()['queued_messages']);
        $this->queue($projection, 'current', 'Still pending');
        $projection->observe(new RunEvent('42', 5000, 0, 'history_position_set', ['reason' => 'continue']), null);
        $this->assertSame(['current' => 'Still pending'], $projection->toArray()['queued_messages']);
    }

    #[DataProvider('settledCommands')]
    public function testSettledAndDiscardedCommandsReleasePendingText(string $type, array $payload): void
    {
        $projection = new SessionResumeMetadataProjection();
        $this->queue($projection, 'current', 'Still pending');
        $projection->observe(new RunEvent('42', 2, 0, $type, $payload), null);
        $this->assertSame([], $projection->toArray()['queued_messages']);
    }

    public static function settledCommands(): iterable
    {
        yield 'applied' => ['agent_command_applied', ['idempotency_key' => 'current']];
        yield 'rejected' => ['agent_command_rejected', ['idempotency_key' => 'current']];
        yield 'cancelled' => ['agent_command_applied', ['kind' => 'cancel']];
        yield 'selection suppresses seed' => ['history_position_set', ['reason' => 'history_select']];
        yield 'discarded' => ['history_tail_discarded', ['after_turn_no' => 0]];
    }

    #[DataProvider('oversizeQueues')]
    public function testActiveInputOverflowIsExplicitAndKeepsThePreviouslyAdmittedMetadata(string $fault): void
    {
        $projection = new SessionResumeMetadataProjection();
        $this->queue($projection, 'current', 'Still pending');
        try {
            if ('bytes' === $fault) {
                $this->queue($projection, 'oversized', str_repeat('x', 4 * 1024 * 1024));
            } else {
                for ($i = 0; $i < 2000; ++$i) {
                    $this->queue($projection, 'message-'.$i, 'Input');
                }
            }
            $this->fail('Active input must not be silently truncated.');
        } catch (\LengthException $exception) {
            $this->assertStringContainsString('bootstrap view budget', $exception->getMessage());
        }
        $this->assertSame('Still pending', $projection->toArray()['queued_messages']['current']);
        $this->assertArrayNotHasKey('oversized', $projection->toArray()['queued_messages']);
    }

    public static function oversizeQueues(): iterable
    {
        yield 'encoded bytes' => ['bytes'];
        yield 'item count' => ['items'];
    }

    public function testUsageAndShellOnlySubmitRoutingSurviveResume(): void
    {
        $projection = new SessionResumeMetadataProjection();
        $projection->observe(new RunEvent('42', 1, 0, 'tool_execution_start', ['tool_name' => 'bash']), null);
        $projection->observe(new RunEvent('42', 2, 0, 'agent_end', ['reason' => 'completed']), null);
        $this->assertTrue($projection->toArray()['is_shell_run']);
        $projection->observe(new RunEvent('42', 3, 0, 'llm_step_completed'), new RuntimeEvent('assistant.message_completed', '42', 3, ['usage' => ['input_tokens' => 41, 'output_tokens' => 7, 'cost' => 0.25, 'cache_read_tokens' => 3]]));
        $projection->observe(new RunEvent('42', 4, 0, 'agent_end', ['reason' => 'completed']), null);
        $data = $projection->toArray();
        $this->assertFalse($data['is_shell_run']);
        $this->assertSame(41, $data['usage']['inputTokens']);
        $this->assertSame(7, $data['usage']['turnOutputTokens']);
        $this->assertSame(0.25, $data['usage']['totalCost']);
        $this->assertSame(3, $data['usage']['cacheReadTokens']);
        $this->assertTrue($data['usage']['hasCacheTelemetry']);
    }

    private function queue(SessionResumeMetadataProjection $projection, string $key, string $text): void
    {
        $projection->observe(new RunEvent('42', 1, 0, 'agent_command_queued'), new RuntimeEvent('user.message_queued', '42', 1, ['idempotency_key' => $key, 'text' => $text]), true);
    }
}
