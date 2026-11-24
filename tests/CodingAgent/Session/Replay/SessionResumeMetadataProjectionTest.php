<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Replay;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Session\Replay\SessionResumeMetadataProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

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
        yield 'selection suppresses seed' => ['history_position_set', ['reason' => 'history_select', 'selected_prompt_turn_no' => 0]];
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

    #[DataProvider('selectionMutations')]
    public function testSelectionIdentityIsClearedByContextMutation(string $type, array $payload): void
    {
        $projection = new SessionResumeMetadataProjection();
        $projection->observe(new RunEvent('42', 1, 1, 'history_position_set', ['reason' => 'history_select', 'selected_prompt_turn_no' => 2]), null);
        $this->assertSame(2, $projection->toArray()['selected_prompt_turn_no']);
        $projection->observe(new RunEvent('42', 2, 1, 'context_refreshed'), null);
        $this->assertSame(2, $projection->toArray()['selected_prompt_turn_no'], 'Attach refresh must preserve the selection.');
        $projection->observe(new RunEvent('42', 3, 1, $type, $payload), null);
        $this->assertArrayNotHasKey('selected_prompt_turn_no', $projection->toArray());
    }

    public static function selectionMutations(): iterable
    {
        yield 'discard' => ['history_tail_discarded', ['after_turn_no' => 1]];
        yield 'advance' => ['turn_advanced', ['turn_no' => 3]];
        yield 'continuation' => ['history_position_set', ['reason' => 'continue']];
        yield 'compaction' => ['context_compacted', []];
        foreach (['follow_up', 'steer', 'append_message', 'compact'] as $kind) {
            yield $kind => ['agent_command_applied', ['kind' => $kind]];
        }
    }

    public function testSelectionRequiresATypedCanonicalPromptIdentity(): void
    {
        $projection = new SessionResumeMetadataProjection();
        $this->expectException(\InvalidArgumentException::class);
        $projection->observe(new RunEvent('42', 1, 1, 'history_position_set', ['reason' => 'history_select', 'selected_prompt_turn_no' => '2']), null);
    }

    public function testForegroundResponseRetainsItsCanonicalOpeningAcrossInternalStepsAndRestore(): void
    {
        $projection = new SessionResumeMetadataProjection();
        $this->assertNull($projection->toArray()['current_foreground_response_seq']);
        $this->fold($projection, 10, 'run_started');
        $this->assertNull($projection->toArray()['current_foreground_response_seq']);
        $this->fold($projection, 12, 'turn_advanced', ['turn_no' => 1]);
        $this->assertSame(12, $projection->toArray()['current_foreground_response_seq']);
        $projection = new SessionResumeMetadataProjection($projection->toArray());
        $this->fold($projection, 18, 'turn_advanced', ['turn_no' => 2]);
        $this->fold($projection, 19, 'llm_step_failed', ['error' => 'Retryable step failure']);
        $this->fold($projection, 20, 'context_refreshed');
        $this->assertSame(12, $projection->toArray()['current_foreground_response_seq']);
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('foregroundTerminalsAndFences')]
    public function testForegroundBoundaryClearsOpeningAndAllowsANewResponse(string $type, array $payload): void
    {
        $projection = new SessionResumeMetadataProjection();
        $this->fold($projection, 12, 'turn_advanced', ['turn_no' => 7]);
        $this->fold($projection, 18, $type, $payload);
        $this->assertNull($projection->toArray()['current_foreground_response_seq']);
        $projection = new SessionResumeMetadataProjection($projection->toArray());
        $this->assertNull($projection->toArray()['current_foreground_response_seq']);
        $this->fold($projection, 25, 'turn_advanced', ['turn_no' => 7]);
        $this->assertSame(25, $projection->toArray()['current_foreground_response_seq']);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function foregroundTerminalsAndFences(): iterable
    {
        foreach (['completed', 'failed', 'cancelled'] as $reason) {
            yield $reason => ['agent_end', ['reason' => $reason]];
        }
        yield 'aborted step' => ['llm_step_aborted', ['stop_reason' => 'aborted']];
        yield 'history selection without runtime event' => ['history_position_set', ['reason' => 'history_select', 'selected_prompt_turn_no' => 2]];
        yield 'history continuation without runtime event' => ['history_position_set', ['reason' => 'continue']];
        yield 'discard without runtime event' => ['history_tail_discarded', ['after_turn_no' => 2]];
    }

    public function testRuntimeOnlyTerminalAndChildActivityDoNotLeaveACompletedIdentity(): void
    {
        $projection = new SessionResumeMetadataProjection();
        $event = new RunEvent('42', 12, 7, 'turn_advanced');
        $projection->observe($event, new RuntimeEvent('turn.started', '42', 0));
        $this->assertNull($projection->toArray()['current_foreground_response_seq']);
        $this->fold($projection, 12, 'turn_advanced');
        $projection->observe($event, new RuntimeEvent('child.execution_ended', '42', 0, ['child_run_id' => '43', 'child_sequence' => 30]));
        $this->assertSame(12, $projection->toArray()['current_foreground_response_seq']);
        $projection->observe($event, new RuntimeEvent('turn.failed', '42', 30));
        $this->assertNull($projection->toArray()['current_foreground_response_seq']);
    }

    #[DataProvider('malformedForegroundIdentities')]
    public function testRestoredForegroundIdentityRequiresAPositiveCanonicalSequence(mixed $sequence): void
    {
        $data = (new SessionResumeMetadataProjection())->toArray();
        $data['current_foreground_response_seq'] = $sequence;
        $this->expectException(\InvalidArgumentException::class);
        new SessionResumeMetadataProjection($data);
    }

    /** @return iterable<array{mixed}> */
    public static function malformedForegroundIdentities(): iterable
    {
        foreach ([0, -1, '12', 12.0, true, []] as $sequence) {
            yield [$sequence];
        }
    }

    public function testForegroundAttentionUsesOnlyIdsAndCategoriesAndClearsOnSupersedingCommands(): void
    {
        foreach (['cancel', 'follow_up', 'steer', 'append_message'] as $kind) {
            $projection = new SessionResumeMetadataProjection();
            $this->fold($projection, 12, 'turn_advanced');
            $this->fold($projection, 13, 'waiting_human', ['question_id' => '1', 'prompt' => 'SECRET']);
            $this->assertSame([['request_id' => '1', 'category' => 'question']], $projection->foreground()['pending_foreground_requests']);
            $this->fold($projection, 14, 'agent_command_applied', ['kind' => $kind]);
            $this->assertSame([], $projection->foreground()['pending_foreground_requests']);
            $this->assertSame(12, $projection->foreground()['current_foreground_response_seq']);
        }
    }

    public function testForegroundWireBudgetRefusesOversizedAttentionWithoutTruncation(): void
    {
        $data = (new SessionResumeMetadataProjection())->toArray();
        $data['pending_foreground_requests'] = array_fill(0, 40, ['request_id' => str_repeat('x', 1024), 'category' => 'question']);
        $this->expectException(\LengthException::class);
        $this->expectExceptionMessage('wire budget');
        new SessionResumeMetadataProjection($data);
    }

    /** @param array<string, mixed> $payload */
    private function fold(SessionResumeMetadataProjection $projection, int $seq, string $type, array $payload = []): void
    {
        $serializer = AttributeSerializerValidatorTestFactory::serializer();
        $translator = new RuntimeEventTranslator(new EventDispatcher(), new ToolExecutionEndPayloadCodec($serializer), $serializer);
        $event = new RunEvent('42', $seq, 7, $type, $payload);
        $projection->observe($event, $translator->translate($event));
    }

    private function queue(SessionResumeMetadataProjection $projection, string $key, string $text): void
    {
        $projection->observe(new RunEvent('42', 1, 0, 'agent_command_queued'), new RuntimeEvent('user.message_queued', '42', 1, ['idempotency_key' => $key, 'text' => $text]), true);
    }
}
