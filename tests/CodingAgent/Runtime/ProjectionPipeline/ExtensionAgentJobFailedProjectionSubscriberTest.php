<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ExtensionAgentJobFailedProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjectionEvent;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Thesis: real TranscriptProjector path maps extension_agent.job_failed to an
 * Error block without marking the run failed (no run.failed block).
 */
final class ExtensionAgentJobFailedProjectionSubscriberTest extends TestCase
{
    #[Test]
    public function projectorMapsExtensionAgentJobFailedToErrorBlock(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionAgentJobFailedProjectionSubscriber());
        $state = new TranscriptProjectionState();
        $projector = new TranscriptProjector($dispatcher, $state);

        $projector->accept(new RuntimeEvent(
            type: RuntimeEventTypeEnum::ExtensionAgentJobFailed->value,
            runId: 'run-1',
            seq: 0,
            payload: [
                'message' => '[usage_limit_reached/insufficient_quota]: You have no credits left.',
                'reason' => 'retry_exhausted',
                'handler_id' => 'observational_memory.observe_boundary',
                'job_id' => 'job-xyz',
                'retry_count' => 1,
                'attempts' => 2,
            ],
        ));

        $blocks = $projector->blocks();
        $this->assertCount(1, $blocks);
        $block = $blocks[0];
        $this->assertSame(TranscriptBlockKindEnum::Error, $block->kind);
        $this->assertSame('run-1', $block->runId);
        $this->assertSame(
            '[usage_limit_reached/insufficient_quota]: You have no credits left.',
            $block->text,
        );
        $this->assertSame('retry_exhausted', $block->meta['reason'] ?? null);
        $this->assertSame('observational_memory.observe_boundary', $block->meta['handler_id'] ?? null);
        $this->assertSame('job-xyz', $block->meta['job_id'] ?? null);
        $this->assertSame(1, $block->meta['retry_count'] ?? null);
        $this->assertSame(2, $block->meta['attempts'] ?? null);

        // No run.failed projection happened — only the extension error block exists.
        $this->assertNotSame(TranscriptBlockKindEnum::System, $block->kind);
        foreach ($blocks as $b) {
            $this->assertStringNotContainsString('Run failed', $b->text);
        }
    }

    #[Test]
    public function subscriberUsesSafeFallbackWhenMessageMissing(): void
    {
        $subscriber = new ExtensionAgentJobFailedProjectionSubscriber();
        $state = new TranscriptProjectionState();
        $event = new TranscriptProjectionEvent(
            runtimeEvent: new RuntimeEvent(
                type: RuntimeEventTypeEnum::ExtensionAgentJobFailed->value,
                runId: 'run-2',
                seq: 0,
                payload: [
                    'reason' => 'retry_exhausted',
                ],
            ),
            state: $state,
        );

        $subscriber->onExtensionAgentJobFailed($event);

        $this->assertCount(1, $state->blocks());
        $this->assertSame(
            'Extension background job failed after retrying.',
            $state->blocks()[0]->text,
        );
        $this->assertSame(TranscriptBlockKindEnum::Error, $state->blocks()[0]->kind);
    }
}
