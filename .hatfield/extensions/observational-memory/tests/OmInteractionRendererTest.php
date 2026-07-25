<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\OmInteractionRenderer;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\RecordObservationsToolHandler;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: renderer bounds oversized tool output; tool validation is model-correctable and single-shot.
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

    public function testAggressiveProfileUsedWhenNormalExceedsBudget(): void
    {
        $huge = str_repeat('tool-output-', 2_000);
        $events = [
            new SessionEventDTO(
                runId: 'run-1',
                seq: 1,
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
                seq: 2,
                turnNo: 1,
                type: 'agent_end',
                payload: ['reason' => 'completed'],
                createdAt: '2026-07-23T00:00:01+00:00',
            ),
        ];

        $renderer = new OmInteractionRenderer();
        // Measure normal estimate, then set budget just below it so aggressive wins.
        $normal = $renderer->render(
            runId: 'run-1',
            events: $events,
            terminalEndSeq: 2,
            terminalStatus: 'completed',
            rendererVersion: 'r1',
            toolResultMaxChars: 4_000,
            inputBudgetTokens: 1_000_000,
        );
        $this->assertSame(OmInteractionRenderer::PROFILE_NORMAL, $normal['profile']);
        $budget = max(1, $normal['token_estimate'] - 1);

        $rendered = $renderer->render(
            runId: 'run-1',
            events: $events,
            terminalEndSeq: 2,
            terminalStatus: 'completed',
            rendererVersion: 'r1',
            toolResultMaxChars: 4_000,
            inputBudgetTokens: $budget,
        );

        $this->assertSame(OmInteractionRenderer::PROFILE_AGGRESSIVE, $rendered['profile']);
        $this->assertLessThanOrEqual($budget, $rendered['token_estimate']);
    }

    public function testOverBudgetAfterAggressiveThrows(): void
    {
        // Assistant text is not tool-bounded, so huge content stays over budget.
        $huge = str_repeat('x', 50_000);
        $events = [
            new SessionEventDTO(
                runId: 'run-1',
                seq: 1,
                turnNo: 1,
                type: 'llm_step_completed',
                payload: [
                    'assistant_message' => [
                        'role' => 'assistant',
                        'content' => $huge,
                    ],
                ],
                createdAt: '2026-07-23T00:00:00+00:00',
            ),
            new SessionEventDTO(
                runId: 'run-1',
                seq: 2,
                turnNo: 1,
                type: 'agent_end',
                payload: ['reason' => 'completed'],
                createdAt: '2026-07-23T00:00:01+00:00',
            ),
        ];

        $renderer = new OmInteractionRenderer();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds observer budget');
        $renderer->render(
            runId: 'run-1',
            events: $events,
            terminalEndSeq: 2,
            terminalStatus: 'completed',
            rendererVersion: 'r1',
            toolResultMaxChars: 4_000,
            inputBudgetTokens: 10,
        );
    }

    public function testRecordObservationsRejectsUnknownCitationWithoutMutation(): void
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
                    'content' => 'fact',
                    'relevance' => 80,
                    'source_refs' => [
                        ['run_id' => 'run-1', 'seq' => 99],
                    ],
                ],
            ],
        ]);

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('invalid_source_refs', $result['error']);
        $this->assertFalse($handler->hasRecorded());
        $this->assertSame([], $handler->collected());
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
        $this->assertTrue($handler->hasRecorded());
    }

    public function testFirstValidRecordingWinsAndLaterCallsReject(): void
    {
        $handler = new RecordObservationsToolHandler(
            runId: 'run-1',
            boundaryKey: 'b1',
            observerSchemaVersion: 'o1',
            sourceStartSeq: 1,
            sourceEndSeq: 5,
            allowedSourceRefs: [
                ['run_id' => 'run-1', 'seq' => 2],
                ['run_id' => 'run-1', 'seq' => 3],
            ],
            maxObservations: 3,
            contentMaxChars: 200,
        );

        $bad = $handler([
            'observations' => [
                [
                    'content' => '',
                    'relevance' => 50,
                    'source_refs' => [['run_id' => 'run-1', 'seq' => 2]],
                ],
            ],
        ]);
        $this->assertSame('rejected', $bad['status']);
        $this->assertFalse($handler->hasRecorded());

        $first = $handler([
            'observations' => [
                [
                    'content' => 'first durable fact',
                    'relevance' => 70,
                    'source_refs' => [['run_id' => 'run-1', 'seq' => 2]],
                ],
            ],
        ]);
        $this->assertSame('accepted', $first['status']);
        $this->assertSame(1, $first['observation_count']);

        $second = $handler([
            'observations' => [
                [
                    'content' => 'second attempt must not overwrite',
                    'relevance' => 90,
                    'source_refs' => [['run_id' => 'run-1', 'seq' => 3]],
                ],
            ],
        ]);
        $this->assertSame('rejected', $second['status']);
        $this->assertSame('already_recorded', $second['error']);
        $this->assertCount(1, $handler->collected());
        $this->assertSame('first durable fact', $handler->collected()[0]['content']);
    }

    public function testEmptyListIsValidFirstRecording(): void
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

        $empty = $handler(['observations' => []]);
        $this->assertSame(['status' => 'accepted', 'observation_count' => 0], $empty);
        $this->assertTrue($handler->hasRecorded());

        $later = $handler([
            'observations' => [
                [
                    'content' => 'too late',
                    'relevance' => 10,
                    'source_refs' => [['run_id' => 'run-1', 'seq' => 2]],
                ],
            ],
        ]);
        $this->assertSame('already_recorded', $later['error']);
        $this->assertSame([], $handler->collected());
    }
}
