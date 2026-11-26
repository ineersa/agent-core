<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryEntryView;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;
use Ineersa\Tui\Runtime\BridgeTuiExtensionContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\TestCase;

final class BridgeTuiExtensionContextTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    /** The public extension contract exposes user prompts with complete titles. */
    public function testTurnRowsInDisplayOrderExcludesAssistantEntriesFromMixedHistory(): void
    {
        $sessionId = 'extension-history-rows';
        $history = new HistoryView(
            entries: [
                new HistoryEntryView(1, 'Create…', 1),
                new HistoryEntryView(1, 'File created', 1, 'assistant'),
                new HistoryEntryView(3, 'Append…', 3),
                new HistoryEntryView(3, 'Line appended', 3, 'assistant'),
            ],
            selectedAnchor: 3,
        );
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $provider = $this->createMock(HistoryProviderInterface::class);
        $provider->expects($this->once())->method('forSession')->with($sessionId, null, 0)->willReturn($history);
        $provider->expects($this->exactly(2))->method('promptText')->willReturnCallback(
            static fn (string $runId, int $turnNo): string => 1 === $turnNo ? 'Create file with complete request' : 'Append line with complete request',
        );
        $runtime = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withScreen($harness->screen())
            ->withState(new TuiSessionState($sessionId))
            ->withHistoryProvider($provider)
            ->build();

        $rows = (new BridgeTuiExtensionContext($runtime))->turnRowsInDisplayOrder($sessionId);

        $this->assertSame([
            ['turnNo' => 1, 'title' => 'Create file with complete request', 'displayRole' => 'user'],
            ['turnNo' => 3, 'title' => 'Append line with complete request', 'displayRole' => 'user'],
        ], $rows);
        $this->assertSame([1, 1, 3, 3], array_column($history->entries, 'turnNo'));
    }
}
