<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SubagentLiveChildViewPollerTest extends TestCase
{
    #[Test]
    public function forkLiveViewFilterHidesRunStartedBootstrapUserMessages(): void
    {
        $poller = new SubagentLiveChildViewPoller(
            new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
            new NullLogger(),
        );

        $blocks = [
            new TranscriptBlock(
                id: 'bootstrap-1',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'child-run-fork',
                seq: 1,
                text: 'Inherited compacted context summary',
                meta: ['bootstrap' => true, 'source' => 'run_started'],
            ),
            new TranscriptBlock(
                id: 'live-1',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'child-run-fork',
                seq: 2,
                text: 'Child live turn',
            ),
        ];

        $method = new \ReflectionMethod(SubagentLiveChildViewPoller::class, 'filterForkLiveTranscriptBlocks');
        $filtered = $method->invoke($poller, $blocks);
        $texts = array_map(static fn (TranscriptBlock $block): string => $block->text, $filtered);

        $this->assertNotContains('Inherited compacted context summary', $texts);
        $this->assertContains('Child live turn', $texts);
    }
}
