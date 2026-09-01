<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\ObserverSystemPrompt;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\OmChunkPacker;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\OmSourceBlockBuilder;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\OmTokenEstimator;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\RecordObservationsToolHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Thesis set for Observer rewrite:
 * - Large interaction under a large context does not hard-fail; a small envelope yields parts.
 * - multi-call accumulate + invalid citations do not mutate; no-call zero-obs is valid accumulation state.
 * - timestamp-last user message; changing only time leaves source digests/IDs unchanged.
 */
final class ObserverChunkAndToolTest extends TestCase
{
    public function testEstimatorUsesConservativeUnicodeCharacterRatio(): void
    {
        $this->assertSame(1, OmTokenEstimator::estimate('abc'));
        $this->assertSame(2, OmTokenEstimator::estimate('abcd'));
        $this->assertSame(4747, OmTokenEstimator::estimate(str_repeat('x', 15_426)));
        $this->assertSame(3250, OmTokenEstimator::characterBudget(1000));
    }

    public function testRunStartedExcludesImmutablePromptContextFromObserverChunks(): void
    {
        $excludedText = [
            'system' => 'SYSTEM INSTRUCTION MUST NOT BE OBSERVED',
            'developer' => 'DEVELOPER INSTRUCTION MUST NOT BE OBSERVED',
            'user-context' => 'PROJECT CONTEXT MUST NOT BE OBSERVED',
        ];
        $userText = 'Retain this real initial user request.';
        $messages = [];
        foreach ($excludedText as $role => $text) {
            $messages[] = [
                'role' => $role,
                'content' => [['type' => 'text', 'text' => $text]],
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => [['type' => 'text', 'text' => $userText]],
        ];

        $blocks = (new OmSourceBlockBuilder())->build([
            new SessionEventDTO(
                'run-started-filtered',
                1,
                0,
                'run_started',
                ['payload' => ['messages' => $messages]],
                '2026-09-01T10:00:00+00:00',
            ),
        ]);

        $this->assertCount(1, $blocks);
        $this->assertSame('message', $blocks[0]['kind']);
        $this->assertStringContainsString($userText, $blocks[0]['rendered_text']);
        foreach ($excludedText as $text) {
            $this->assertStringNotContainsString($text, $blocks[0]['rendered_text']);
        }

        $parts = (new OmChunkPacker())->pack(
            runId: 'run-started-filtered',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            blocks: $blocks,
            memoryReflections: [],
            memoryObservations: [],
            envelopeTokens: 4_000,
            localTimeFallback: '2026-09-01 10:00',
            fixedOverheadTokens: OmTokenEstimator::estimate(ObserverSystemPrompt::text()) + 50,
        );
        $this->assertCount(1, $parts);
        $this->assertStringContainsString($userText, $parts[0]['user_message']);
        foreach ($excludedText as $text) {
            $this->assertStringNotContainsString($text, $parts[0]['user_message']);
        }
    }

    public function testRunStartedWithOnlyImmutablePromptContextBuildsNoObserverBlocks(): void
    {
        $blocks = (new OmSourceBlockBuilder())->build([
            new SessionEventDTO(
                'run-started-empty',
                1,
                0,
                'run_started',
                ['payload' => ['messages' => [
                    ['role' => 'system', 'content' => 'system instruction'],
                    ['role' => 'developer', 'content' => 'developer instruction'],
                    ['role' => 'user-context', 'content' => 'project context'],
                ]]],
                '2026-09-01T10:00:00+00:00',
            ),
        ]);

        $this->assertSame([], $blocks);
    }

    public function testToolResultsRemainCompleteThroughFiveThousandEstimatedTokens(): void
    {
        $complete = str_repeat('a', 16_250);
        $oversized = str_repeat('b', 16_251);
        $blocks = (new OmSourceBlockBuilder())->build([
            new SessionEventDTO(
                'run-tool-results',
                1,
                1,
                'tool_execution_end',
                ['tool_result' => [
                    'tool_call_id' => 'complete',
                    'result' => ['tool_name' => 'read', 'content' => [['type' => 'text', 'text' => $complete]]],
                    'is_error' => false,
                ]],
                '2026-07-26T10:00:00+00:00',
            ),
            new SessionEventDTO(
                'run-tool-results',
                2,
                1,
                'tool_execution_end',
                ['tool_result' => [
                    'tool_call_id' => 'oversized',
                    'result' => ['tool_name' => 'read', 'content' => [['type' => 'text', 'text' => $oversized]]],
                    'is_error' => false,
                ]],
                '2026-07-26T10:00:01+00:00',
            ),
        ]);

        $this->assertCount(2, $blocks);
        $this->assertStringContainsString($complete, $blocks[0]['rendered_text']);
        $this->assertStringContainsString(
            '[tool output digest sha256='.hash('sha256', $oversized).' chars=16251]',
            $blocks[1]['rendered_text'],
        );
        $this->assertStringNotContainsString(str_repeat('b', 601), $blocks[1]['rendered_text']);
    }

    public function testLargeInteractionPacksUnderEnvelopeInsteadOfHardFail(): void
    {
        $big = str_repeat('word ', 4_000); // ~20k chars, ~6.2k estimated tokens
        $events = [
            new SessionEventDTO('run-1', 1, 1, 'agent_command_applied', ['text' => $big], '2026-07-26T10:00:00+00:00'),
            new SessionEventDTO('run-1', 2, 1, 'agent_end', ['reason' => 'completed'], '2026-07-26T10:01:00+00:00'),
        ];
        $blocks = (new OmSourceBlockBuilder())->build($events);
        $this->assertNotSame([], $blocks);

        $system = ObserverSystemPrompt::text();
        $packer = new OmChunkPacker();

        $fixed = OmTokenEstimator::estimate($system) + 50;

        // Large context: 128k * 0.65 admits the interaction as few parts.
        $large = $packer->pack(
            runId: 'run-1',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            blocks: $blocks,
            memoryReflections: [],
            memoryObservations: [],
            envelopeTokens: (int) floor(128_000 * 0.65),
            localTimeFallback: '2026-07-26 12:00',
            fixedOverheadTokens: $fixed,
        );
        $this->assertNotSame([], $large);
        $this->assertLessThanOrEqual(3, \count($large));

        // Tiny envelope forces UTF-8 parts rather than fatal whole-range rejection.
        $small = $packer->pack(
            runId: 'run-1',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            blocks: $blocks,
            memoryReflections: [],
            memoryObservations: [],
            envelopeTokens: 4_000,
            localTimeFallback: '2026-07-26 12:00',
            fixedOverheadTokens: $fixed,
        );
        $this->assertGreaterThan(1, \count($small));
        foreach ($small as $part) {
            $this->assertLessThanOrEqual(4_000, $part['token_estimate'] + $fixed);
            $this->assertStringContainsString('CURRENT REFLECTIONS:', $part['user_message']);
            $this->assertStringContainsString('NEW SOURCE-ADDRESSED CONVERSATION CHUNK:', $part['user_message']);
            $this->assertMatchesRegularExpression('/Current local time fallback: \d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $part['user_message']);
            $this->assertStringEndsWith('Current local time fallback: 2026-07-26 12:00', trim($part['user_message']));
        }

        // Source digests/keys ignore local time.
        $otherTime = $packer->pack(
            runId: 'run-1',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            blocks: $blocks,
            memoryReflections: [],
            memoryObservations: [],
            envelopeTokens: 4_000,
            localTimeFallback: '2099-01-01 00:00',
            fixedOverheadTokens: $fixed,
        );
        $this->assertSame($small[0]['source_digest'], $otherTime[0]['source_digest']);
        $this->assertSame($small[0]['chunk_key'], $otherTime[0]['chunk_key']);
        $this->assertSame($small[0]['part_digest'], $otherTime[0]['part_digest']);
    }

    public function testSeparatorAwareGroupingKeepsAtomicToolPairAndSplitsOversizedUtf8(): void
    {
        $builder = new OmSourceBlockBuilder();
        $events = [
            new SessionEventDTO(
                'run-p',
                1,
                1,
                'llm_step_completed',
                [
                    'assistant_message' => [
                        'role' => 'assistant',
                        'content' => 'Reading file',
                        'tool_calls' => [[
                            'id' => 'tc-1',
                            'name' => 'read',
                            'arguments' => ['path' => 'a.txt'],
                        ]],
                    ],
                ],
                '2026-07-26T10:00:00+00:00',
            ),
            new SessionEventDTO(
                'run-p',
                2,
                1,
                'tool_execution_end',
                ['tool_result' => [
                    'run_id' => 'run-p',
                    'turn_no' => 1,
                    'step_id' => 'step-1',
                    'attempt' => 1,
                    'idempotency_key' => 'result-tc-1',
                    'tool_call_id' => 'tc-1',
                    'order_index' => 0,
                    'result' => ['tool_name' => 'read', 'content' => [['type' => 'text', 'text' => 'file body']]],
                    'is_error' => false,
                    'error' => null,
                    'pending_human_input' => null,
                ]],
                '2026-07-26T10:00:01+00:00',
            ),
            new SessionEventDTO(
                'run-p',
                3,
                1,
                'agent_command_applied',
                ['text' => str_repeat('z', 2_000)],
                '2026-07-26T10:00:02+00:00',
            ),
        ];
        $blocks = $builder->build($events);
        $this->assertGreaterThanOrEqual(2, \count($blocks));
        $this->assertSame('tool_group', $blocks[0]['kind']);

        $packer = new OmChunkPacker();
        $system = ObserverSystemPrompt::text();
        $fixed = OmTokenEstimator::estimate($system) + 50;

        // Envelope large enough for the atomic tool pair alone, but not tool pair + large user block.
        $packed = $packer->pack(
            runId: 'run-p',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            blocks: $blocks,
            memoryReflections: [],
            memoryObservations: [],
            envelopeTokens: 900,
            localTimeFallback: '2026-07-26 12:00',
            fixedOverheadTokens: $fixed,
        );
        $this->assertGreaterThanOrEqual(2, \count($packed));
        // Atomic tool_group is one block (call-event seq) and must not be UTF-8-split across parts.
        $this->assertSame(1, $packed[0]['source_start_seq']);
        $this->assertSame(1, $packed[0]['source_end_seq']);
        $this->assertSame(1, $packed[0]['part_count']);
        $this->assertStringContainsString('Tool call', $packed[0]['rendered_part']);
        $this->assertStringContainsString('Tool result', $packed[0]['rendered_part']);
        $this->assertContains(['run_id' => 'run-p', 'seq' => 2], $packed[0]['source_refs']);

        // Oversized single block still UTF-8 parts.
        $hugeBlock = [[
            'run_id' => 'run-p',
            'seq' => 9,
            'kind' => 'user',
            'rendered_text' => str_repeat('w', 5_000),
            'source_refs' => [['run_id' => 'run-p', 'seq' => 9]],
        ]];
        $parts = $packer->pack(
            runId: 'run-p',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            blocks: $hugeBlock,
            memoryReflections: [],
            memoryObservations: [],
            envelopeTokens: 400,
            localTimeFallback: '2026-07-26 12:00',
            fixedOverheadTokens: $fixed,
        );
        $this->assertGreaterThan(1, \count($parts));
        foreach ($parts as $part) {
            $this->assertSame(9, $part['source_start_seq']);
            $this->assertSame(9, $part['source_end_seq']);
        }
        $this->assertSame(\count($parts), $parts[0]['part_count']);
    }

    public function testMultiCallAccumulateAndInvalidCitationDoesNotMutate(): void
    {
        $handler = new RecordObservationsToolHandler(
            runId: 'run-1',
            observerSchemaVersion: '1',
            allowedSourceRefs: [
                ['run_id' => 'run-1', 'seq' => 1],
                ['run_id' => 'run-1', 'seq' => 2],
            ],
        );

        $r1 = $handler([
            'observations' => [[
                'timestamp' => '2026-07-26 10:00',
                'content' => 'User prefers feature flags',
                'relevance' => 'high',
                'source_refs' => [['run_id' => 'run-1', 'seq' => 1]],
            ]],
        ]);
        $this->assertSame('accepted', $r1['status']);
        $this->assertSame(1, $r1['added']);
        $this->assertSame(1, $r1['total']);

        $bad = $handler([
            'observations' => [[
                'timestamp' => '2026-07-26 10:01',
                'content' => 'Invalid citation',
                'relevance' => 'medium',
                'source_refs' => [['run_id' => 'run-1', 'seq' => 99]],
            ]],
        ]);
        $this->assertSame('rejected', $bad['status']);
        $this->assertSame(1, \count($handler->collected()));

        $r2 = $handler([
            'observations' => [[
                'timestamp' => '2026-07-26 10:02',
                'content' => 'Agent completed rollout checklist',
                'relevance' => 'medium',
                'source_refs' => [['run_id' => 'run-1', 'seq' => 2]],
            ]],
        ]);
        $this->assertSame('accepted', $r2['status']);
        $this->assertSame(2, \count($handler->collected()));

        $id = OmIdentity::observationId(
            'run-1',
            '1',
            '2026-07-26 10:00',
            'User prefers feature flags',
            [['run_id' => 'run-1', 'seq' => 1]],
        );
        $this->assertSame($id, $handler->collected()[0]['observation_id']);
    }

    public function testNoToolCallLeavesEmptyCollectionValid(): void
    {
        $handler = new RecordObservationsToolHandler(
            runId: 'run-1',
            observerSchemaVersion: '1',
            allowedSourceRefs: [['run_id' => 'run-1', 'seq' => 1]],
        );
        $this->assertSame([], $handler->collected());
    }
}
