<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\OmInteractionRenderer;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\RecordObservationsToolHandler;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: renderer bounds oversized tool output and citation tool validates allowed refs.
 */
final class OmInteractionRendererTest extends TestCase
{
    public function testToolResultIsBoundedWithDigest(): void
    {
        $huge = str_repeat('x', 10_000);
        $events = [
            new SessionEventDTO(
                runId: 'run-1',
                seq: 3,
                turnNo: 1,
                type: 'tool_execution_end',
                payload: [
                    'tool_call_id' => 'tc1',
                    'result' => $huge,
                    'is_error' => false,
                ],
                createdAt: '2026-07-23T00:00:00+00:00',
            ),
            new SessionEventDTO(
                runId: 'run-1',
                seq: 4,
                turnNo: 1,
                type: 'agent_end',
                payload: ['reason' => 'completed'],
                createdAt: '2026-07-23T00:00:01+00:00',
            ),
        ];

        $renderer = new OmInteractionRenderer();
        $rendered = $renderer->render(
            runId: 'run-1',
            events: $events,
            terminalEndSeq: 4,
            terminalStatus: 'completed',
            rendererVersion: 'r1',
            toolResultMaxChars: 400,
            inputBudgetTokens: 50_000,
        );

        $this->assertStringContainsString('[truncated]', $rendered['text']);
        $this->assertStringContainsString('sha256: '.hash('sha256', $huge), $rendered['text']);
        $this->assertStringNotContainsString($huge, $rendered['text']);
        $this->assertSame(3, $rendered['source_start_seq']);
        $this->assertSame(4, $rendered['source_end_seq']);
    }

    public function testRecordObservationsRejectsUnknownCitation(): void
    {
        $handler = new RecordObservationsToolHandler(
            runId: 'run-1',
            boundaryKey: 'b1',
            observerSchemaVersion: 'o1',
            sourceStartSeq: 1,
            sourceEndSeq: 5,
            allowedSourceRefs: [
                ['run_id' => 'run-1', 'seq' => 2],
            ],
            maxObservations: 3,
            contentMaxChars: 200,
        );

        $this->expectException(\InvalidArgumentException::class);
        $handler([
            'observations' => [
                [
                    'content' => 'fact',
                    'relevance' => 80,
                    'source_refs' => [
                        ['run_id' => 'run-1', 'seq' => 99],
                    ],
                ],
            ],
        ]);
    }

    public function testRecordObservationsAcceptsZeroAndDedupes(): void
    {
        $handler = new RecordObservationsToolHandler(
            runId: 'run-1',
            boundaryKey: 'b1',
            observerSchemaVersion: 'o1',
            sourceStartSeq: 1,
            sourceEndSeq: 5,
            allowedSourceRefs: [
                ['run_id' => 'run-1', 'seq' => 2],
            ],
            maxObservations: 3,
            contentMaxChars: 200,
        );

        $result = $handler([
            'observations' => [
                [
                    'content' => 'same',
                    'relevance' => 50,
                    'source_refs' => [['run_id' => 'run-1', 'seq' => 2]],
                ],
                [
                    'content' => 'same',
                    'relevance' => 50,
                    'source_refs' => [['run_id' => 'run-1', 'seq' => 2]],
                ],
            ],
        ]);

        $this->assertSame(['status' => 'accepted', 'observation_count' => 1], $result);
        $this->assertCount(1, $handler->collected());
    }
}
