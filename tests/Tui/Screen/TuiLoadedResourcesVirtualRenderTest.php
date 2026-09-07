<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceConflictDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceItemDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceSectionDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourcesSummaryDTO;
use Ineersa\Tui\Runtime\BridgeTuiExtensionContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Virtual proof that the loaded-resources block renders on ChatScreen startup.
 */
final class TuiLoadedResourcesVirtualRenderTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private const string SESSION_ID = 'virtual-loaded-resources';

    #[Test]
    public function extensionWarningsRenderInStartupResourcesEvenOnResume(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $runtime = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withScreen($harness->screen())
            ->withState(new TuiSessionState(self::SESSION_ID))
            ->build();
        $bridge = new BridgeTuiExtensionContext($runtime);
        $bridge->setStatus('other-extension', 'Other extension status');
        $harness->screen()->setLoadedResourcesSummary(null);
        $bridge->setExtensionWarning('example', 'Configuration missing. Open the project settings to fix it.');
        $screen = $harness->plainScreenText();
        $this->assertStringContainsString("[Extensions]\n  ⚠ example: Configuration missing.", $screen);
        $this->assertStringContainsString('Other extension status', $screen);
        $this->assertSame(1, substr_count($screen, '⚠ example:'));
        $this->assertLessThan(strpos($screen, 'Other extension status'), strpos($screen, '⚠ example:'));

        // A late startup-summary update preserves asynchronously reported warnings.
        $harness->screen()->setLoadedResourcesSummary(new LoadedResourcesSummaryDTO([
            new LoadedResourceSectionDTO('Extensions', [new LoadedResourceItemDTO('example', '/extension')]),
        ]));
        $this->assertStringContainsString("[Extensions]  example\n  ⚠ example:", $harness->plainScreenText());
        $bridge->setExtensionWarning('example', null);
        $screen = $harness->plainScreenText();
        $this->assertStringNotContainsString('⚠ example:', $screen);
        $this->assertStringContainsString('Other extension status', $screen);
    }

    #[Test]
    public function testStartupShowsLoadedResourcesBlockOnFreshSession(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $summary = new LoadedResourcesSummaryDTO([
            new LoadedResourceSectionDTO(
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
