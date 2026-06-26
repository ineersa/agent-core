<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceConflictDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceItemDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceSectionDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourcesSummaryDTO;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Virtual proof that the loaded-resources block renders on ChatScreen startup.
 */
final class TuiLoadedResourcesVirtualRenderTest extends TestCase
{
    private const string SESSION_ID = 'virtual-loaded-resources';

    #[Test]
    public function testStartupShowsLoadedResourcesBlockOnFreshSession(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $summary = new LoadedResourcesSummaryDTO([
            new LoadedResourceSectionDTO(
                key: 'skills',
                label: 'Skills',
                items: [new LoadedResourceItemDTO('testing', '/skills/testing/SKILL.md')],
                conflicts: [
                    new LoadedResourceConflictDTO('dup', '/winner/SKILL.md', '/loser/SKILL.md'),
                ],
            ),
        ]);

        $harness->screen()->setLoadedResourcesSummary($summary);
        $factory = new TranscriptBlockFactory();
        $welcome = $factory->system(
            runId: self::SESSION_ID,
            text: 'Welcome to Hatfield. Type a message below to start.',
            seq: 1,
        );
        $harness->screen()->setTranscriptBlocks([$welcome]);

        $screen = $harness->plainScreenText();

        $this->assertStringContainsString('[Skills]', $screen);
        $this->assertStringContainsString('testing', $screen);
        $this->assertStringContainsString('won /winner/SKILL.md', $screen);
        $this->assertStringContainsString('ignored /loser/SKILL.md', $screen);
        $this->assertStringContainsString('Welcome to Hatfield', $screen);
    }

    #[Test]
    public function testResumedSessionDoesNotShowLoadedResourcesBlock(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);

        // InteractiveMode::resolveLoadedResourcesSummary() passes null when $state->resuming is true.
        // ChatScreen mirrors that by omitting the block when summary is null.
        $harness->screen()->setLoadedResourcesSummary(null);

        $factory = new TranscriptBlockFactory();
        $welcome = $factory->system(
            runId: self::SESSION_ID,
            text: 'Resumed run abc123',
            seq: 1,
        );
        $harness->screen()->setTranscriptBlocks([$welcome]);

        $screen = $harness->plainScreenText();

        $this->assertStringContainsString('Resumed run abc123', $screen);
        $this->assertStringNotContainsString('[Skills]', $screen);
        $this->assertStringNotContainsString('ctrl+r to expand', $screen);
    }
}
