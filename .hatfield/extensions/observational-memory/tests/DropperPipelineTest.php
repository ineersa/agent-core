<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Compaction\DropObservationsToolHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Compaction\DropperPipeline;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: Dropper safety — deterministic ranking + hard cap; model cannot delete directly.
 */
final class DropperPipelineTest extends TestCase
{
    public function testMaxDropCountAndSelectOrderingCap(): void
    {
        // 4 obs × 100 tokens = 400; target 300 → over 100 → avg 100 → ceil(1)=1
        $this->assertSame(1, DropperPipeline::maxDropCountForPool(4, 400, 300));
        // 550 tokens, avg 137.5, over 250 → ceil(250/137.5)=2
        $this->assertSame(2, DropperPipeline::maxDropCountForPool(4, 550, 300));
        // exact Pi formula edge: over enough for 3
        $this->assertSame(3, DropperPipeline::maxDropCountForPool(4, 700, 300));
        $this->assertSame(0, DropperPipeline::maxDropCountForPool(4, 300, 300));
        $this->assertSame(0, DropperPipeline::maxDropCountForPool(0, 100, 50));

        $observations = [
            $this->obs('none-critical-old', 'critical', '2020-01-01 00:00'),
            $this->obs('strong-low-new', 'low', '2026-01-02 00:00'),
            $this->obs('partial-medium', 'medium', '2024-01-01 00:00'),
            $this->obs('strong-low-old', 'low', '2021-01-01 00:00'),
            $this->obs('none-low', 'low', '2023-01-01 00:00'),
            $this->obs('unproposed', 'low', '2019-01-01 00:00'),
        ];
        $reflections = [
            [
                'reflection_id' => 'r1',
                'supporting_observation_ids' => ['strong-low-new', 'strong-low-old'],
            ],
            [
                'reflection_id' => 'r2',
                'supporting_observation_ids' => ['strong-low-new', 'strong-low-old', 'partial-medium'],
            ],
        ];

        // Proposal order intentionally worst-first; ranking must still prefer strong+low+old.
        $proposed = [
            'none-critical-old',
            'none-low',
            'partial-medium',
            'strong-low-new',
            'strong-low-old',
            'unknown-id',
            'strong-low-old', // duplicate
        ];

        $selected = DropperPipeline::selectDropCandidates($proposed, $observations, $reflections, 3);
        $this->assertSame(
            ['strong-low-old', 'strong-low-new', 'partial-medium'],
            $selected,
            'coverage→relevance→age must dominate proposal order',
        );
        $this->assertNotContains('unproposed', $selected);
        $this->assertNotContains('unknown-id', $selected);
        $this->assertNotContains('none-critical-old', $selected);
    }

    public function testToolAccumulatesWithoutDeletingAndUnknownIdsIgnored(): void
    {
        $handler = new DropObservationsToolHandler(
            allowedObservationIds: ['a' => true, 'b' => true, 'c' => true],
            maxDropsAllowed: 2,
        );

        $r1 = $handler(['ids' => ['a', 'missing', 'a', 'b'], 'reason' => 'optional']);
        $this->assertSame('accepted', $r1['status']);
        $this->assertSame(2, $r1['added']);
        $this->assertSame(1, $r1['missing']);
        $this->assertSame(1, $r1['duplicate_in_request']);
        $this->assertSame(['a', 'b'], $handler->proposedIds());

        $r2 = $handler(['ids' => ['b', 'c']]);
        $this->assertSame(1, $r2['added']);
        $this->assertSame(1, $r2['duplicate_in_run']);
        $this->assertSame(['a', 'b', 'c'], $handler->proposedIds());

        // No call → empty proposals → select returns empty even with high cap.
        $empty = DropperPipeline::selectDropCandidates(
            [],
            [$this->obs('a', 'low', '2020-01-01 00:00')],
            [],
            10,
        );
        $this->assertSame([], $empty);
    }

    public function testModelProposalDoesNotDirectlyControlDeletion(): void
    {
        $observations = [
            $this->obs('keep-critical-uncovered', 'critical', '2010-01-01 00:00'),
            $this->obs('drop-strong-low', 'low', '2011-01-01 00:00'),
        ];
        $reflections = [[
            'reflection_id' => 'r1',
            'supporting_observation_ids' => ['drop-strong-low'],
        ], [
            'reflection_id' => 'r2',
            'supporting_observation_ids' => ['drop-strong-low'],
        ]];

        // Model proposes critical first and only wants both dropped, but cap=1 + ranking
        // must select the strong-low covered observation, not the critical uncovered one.
        $selected = DropperPipeline::selectDropCandidates(
            ['keep-critical-uncovered', 'drop-strong-low'],
            $observations,
            $reflections,
            1,
        );
        $this->assertSame(['drop-strong-low'], $selected);
        $this->assertNotSame(['keep-critical-uncovered', 'drop-strong-low'], $selected);
    }

    /**
     * @return array{observation_id: string, content: string, relevance: string, timestamp: string, token_count: int}
     */
    private function obs(string $id, string $relevance, string $timestamp): array
    {
        return [
            'observation_id' => $id,
            'content' => 'content '.$id,
            'relevance' => $relevance,
            'timestamp' => $timestamp,
            'token_count' => 10,
        ];
    }
}
