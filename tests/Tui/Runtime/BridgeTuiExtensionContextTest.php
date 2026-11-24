<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundWorkSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryEntryView;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;
use Ineersa\Tui\Runtime\BridgeTuiExtensionContext;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\TestCase;

final class BridgeTuiExtensionContextTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    public function testActivityAndInventoryAreScopedToTheReadySelection(): void
    {
        $state = new TuiSessionState('current');
        $harness = new VirtualTuiHarness(sessionId: 'current');
        $client = $this->createMock(AgentSessionClient::class);
        $calls = 0;
        $client->expects($this->exactly(3))->method('backgroundWork')->with('current')->willReturnCallback(static function () use ($state, &$calls): BackgroundWorkSnapshotDTO {
            ++$calls;
            if (2 === $calls) {
                throw new \RuntimeException('Inventory unavailable');
            }
            if (3 === $calls) {
                $state->sessionId = 'other';
            }

            return new BackgroundWorkSnapshotDTO([], []);
        });
        $bridge = new BridgeTuiExtensionContext($this->buildTuiContext()
            ->withTui($harness->tui())->withScreen($harness->screen())->withState($state)->withClient($client)->build());

        $state->activity = RunActivityStateEnum::Running;
        $this->assertNull($bridge->isMainActive(), 'Replayed activity is not live without a handle.');
        $state->handle = new RunHandle('other');
        $this->assertNull($bridge->getBackgroundAgents());
        $state->handle = new RunHandle('current');
        $state->sessionReady = false;
        $this->assertNull($bridge->isMainActive());
        $this->assertNull($bridge->getBackgroundJobs());
        $state->sessionReady = true;
        $this->assertTrue($bridge->isMainActive());
        $snapshot = $bridge->getBackgroundSnapshot();
        $this->assertSame(0, $snapshot->activeSubagents);
        $this->assertSame(0, $snapshot->backgroundScripts);
        $this->assertNull($bridge->getBackgroundAgents(), 'Read failure is not an empty inventory.');
        $this->assertNull($bridge->getBackgroundJobs(), 'A selection change invalidates the in-flight read.');
    }

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
