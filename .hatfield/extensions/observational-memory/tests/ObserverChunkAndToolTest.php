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
 * - 15k-token interaction under large context does not hard-fail at 12k; small envelope yields parts.
 * - multi-call accumulate + invalid citations do not mutate; no-call zero-obs is valid accumulation state.
 * - timestamp-last user message; changing only time leaves source digests/IDs unchanged.
 */
final class ObserverChunkAndToolTest extends TestCase
{
    public function testEstimatorIsCeilUnicodeLengthOverFour(): void
    {
        $this->assertSame(1, OmTokenEstimator::estimate('abcd'));
        $this->assertSame(2, OmTokenEstimator::estimate('abcde'));
        $this->assertSame(3857, OmTokenEstimator::estimate(str_repeat('x', 15_426)));
    }

    public function testLargeInteractionPacksUnderEnvelopeInsteadOfHardFail(): void
    {
        $big = str_repeat('word ', 4_000); // ~20k chars ~5k tokens
        $events = [
            new SessionEventDTO('run-1', 1, 1, 'agent_command_applied', ['text' => $big], '2026-07-26T10:00:00+00:00'),
            new SessionEventDTO('run-1', 2, 1, 'agent_end', ['reason' => 'completed'], '2026-07-26T10:01:00+00:00'),
        ];
        $blocks = (new OmSourceBlockBuilder())->build($events);
        $this->assertNotSame([], $blocks);

        $system = ObserverSystemPrompt::text();
        $packer = new OmChunkPacker();

        // Large context: 128k * 0.65 admits the interaction as few parts.
        $large = $packer->pack(
            runId: 'run-1',
            rendererVersion: '1',
            observerSchemaVersion: '1',
            blocks: $blocks,
            memoryReflections: [],
            memoryObservations: [],
            envelopeTokens: (int) floor(128_000 * 0.65),
            systemPrompt: $system,
            toolSchemaEstimateText: 'schema',
            localTimeFallback: '2026-07-26 12:00',
            fixedOverheadTokens: OmTokenEstimator::estimate($system) + 50,
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
            envelopeTokens: 800,
            systemPrompt: $system,
            toolSchemaEstimateText: 'schema',
            localTimeFallback: '2026-07-26 12:00',
            fixedOverheadTokens: OmTokenEstimator::estimate($system) + 50,
        );
        $this->assertGreaterThan(1, \count($small));
        foreach ($small as $part) {
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
            envelopeTokens: 800,
            systemPrompt: $system,
            toolSchemaEstimateText: 'schema',
            localTimeFallback: '2099-01-01 00:00',
            fixedOverheadTokens: OmTokenEstimator::estimate($system) + 50,
        );
        $this->assertSame($small[0]['source_digest'], $otherTime[0]['source_digest']);
        $this->assertSame($small[0]['chunk_key'], $otherTime[0]['chunk_key']);
        $this->assertSame($small[0]['part_digest'], $otherTime[0]['part_digest']);
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
        $this->assertFalse($handler->hasAnyCall());
        $this->assertSame([], $handler->collected());
    }
}
