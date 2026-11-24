<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Amp\Future;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiBackgroundJobObservationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiBackgroundSnapshotDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiRunObservationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiBackgroundJobObservationTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObservationTypeEnum as Observation;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiSessionActivityContextInterface;
use Ineersa\HatfieldExt\Hatfield360\Hatfield360Extension;
use PHPUnit\Framework\TestCase;

final class Hatfield360ExtensionTest extends TestCase
{
    public function testLiveDuplicateRequestsRetainTheSecondOccurrenceAfterOneAnswer(): void
    {
        $extension = $this->extension();
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::TurnStarted));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 2, Observation::QuestionRequested, 'same'));
        $first = $this->attention($extension);
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 3, Observation::ApprovalRequested, 'same'));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 4, Observation::InputResolved, 'same'));
        $second = $this->attention($extension);
        $this->assertSame('permission', $second['reason']);
        $this->assertNotSame($first['id'], $second['id']);
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 5, Observation::InputResolved, 'same'));
        $this->assertNull($this->attention($extension));
    }

    public function testLiveDuplicatesAndOverflowDoNotEraseUnknownOrChildAttention(): void
    {
        $extension = $this->extension();
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::TurnStarted));
        for ($i = 0; $i < 2001; ++$i) {
            $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 2 + $i, Observation::QuestionRequested, 'same'));
        }
        for ($i = 0; $i < 2001; ++$i) {
            $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 3000 + $i, Observation::InputResolved, 'same'));
        }
        $this->assertNotNull($this->attention($extension), 'Overflow must not be resolved by an uncorrelated answer.');
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::ApprovalRequested, 'child-question', 'child'));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 6000, Observation::TurnCompleted));
        $this->assertSame('permission', $this->attention($extension)['reason']);
    }

    public function testCompletionRefreshesInventoryInEitherSettlementOrder(): void
    {
        $extension = $this->extension();
        $context = $this->createStub(TuiSessionActivityContextInterface::class);
        $context->method('getSessionId')->willReturn('42');
        $active = true;
        $children = 1;
        $jobs = 1;
        $reads = [];
        $context->method('isMainActive')->willReturnCallback(static function () use (&$active): bool {
            return $active;
        });
        $context->method('getBackgroundSnapshot')->willReturnCallback(static function (bool $refresh) use (&$children, &$jobs, &$reads): TuiBackgroundSnapshotDTO {
            $reads[] = $refresh;

            return new TuiBackgroundSnapshotDTO('42', $children, $jobs);
        });
        $extension->registerTui($context);

        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::TurnStarted));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 2, Observation::QuestionRequested, 'question'));
        $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('42', 0, 1));
        $this->assertSame([false, false], $reads, 'Ordinary observations use the host sample; poll callbacks already have it.');
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::ChildExecutionStarted, childRunId: 'child'));
        $this->assertSame(1, $this->snapshot($extension)['activeSubagents']);
        $active = false;
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 2, Observation::TurnCompleted));
        $this->assertSame(1, $this->snapshot($extension)['activeSubagents']);
        $completed = ['id' => '1', 'status' => 'completed'];
        $this->assertSame($completed, $this->snapshot($extension)['turn']);
        $children = 0;
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::ChildExecutionEnded, childRunId: 'child'));
        $this->assertSame(0, $this->snapshot($extension)['activeSubagents']);
        $this->assertSame(1, $this->snapshot($extension)['backgroundScripts']);
        $this->assertSame($completed, $this->snapshot($extension)['turn']);
        $jobs = 0;
        $extension->onTuiBackgroundJobObserved(new TuiBackgroundJobObservationDTO('42', '42', 'bash:1', TuiBackgroundJobObservationTypeEnum::Ended));
        $this->assertSame(0, $this->snapshot($extension)['backgroundScripts']);
        $this->assertSame($completed, $this->snapshot($extension)['turn'], 'Script changes do not mint another completion identity.');

        $children = 1;
        $active = true;
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 3, Observation::TurnStarted));
        $children = 0;
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 2, Observation::ChildExecutionEnded, childRunId: 'child'));
        $this->assertSame(0, $this->snapshot($extension)['activeSubagents']);
        $this->assertSame('working', $this->snapshot($extension)['turn']['status']);
        $active = false;
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 4, Observation::TurnCompleted));
        $this->assertSame(['id' => '3', 'status' => 'completed'], $this->snapshot($extension)['turn']);
        $this->assertSame(0, $this->snapshot($extension)['activeSubagents']);
        $this->assertSame([false, false, true, true, true, true, false, true, true], $reads);
    }

    public function testUnknownStateAndMainReactivationCannotSettleOldCompletion(): void
    {
        $extension = $this->extension();
        $context = $this->createStub(TuiSessionActivityContextInterface::class);
        $selection = '42';
        $active = null;
        $context->method('getSessionId')->willReturnCallback(static function () use (&$selection): string {
            return $selection;
        });
        $context->method('isMainActive')->willReturnCallback(static function () use (&$active): ?bool {
            return $active;
        });
        $context->method('getBackgroundSnapshot')->willReturn(new TuiBackgroundSnapshotDTO('42', 0, 0));
        $extension->registerTui($context);
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::TurnStarted));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 2, Observation::TurnCompleted));
        $incomplete = $this->snapshot($extension);
        $this->assertNull($incomplete['activeSubagents']);
        $selection = 'other';
        $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('42', 0, 1));
        $this->assertSame($incomplete, $this->snapshot($extension));
        $selection = '42';
        $active = true; // The TUI accepted new input before its canonical opening event arrived.
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::ChildExecutionEnded, childRunId: 'child'));
        $this->assertNull($this->snapshot($extension)['turn']);
        $active = false;
        $extension->onTuiBackgroundSnapshot(new TuiBackgroundSnapshotDTO('42', 0, 1));
        $this->assertNull($this->snapshot($extension)['turn']);
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 3, Observation::TurnStarted));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 4, Observation::TurnFailed));
        $this->assertSame(['id' => '3', 'status' => 'failed'], $this->snapshot($extension)['turn']);
    }

    private function extension(): Hatfield360Extension
    {
        $extension = new Hatfield360Extension();
        (new \ReflectionProperty($extension, 'sessionId'))->setValue($extension, '42');
        // Hold the outbound queue locally: exercise real live observations
        // without registration, credentials, sockets, or an asynchronous delivery race.
        (new \ReflectionProperty($extension, 'drain'))->setValue($extension, Future::complete());

        return $extension;
    }

    /** @return array{id: string, reason: string}|null */
    private function attention(Hatfield360Extension $extension): ?array
    {
        return $this->snapshot($extension)['attention'];
    }

    /** @return array<string, mixed> */
    private function snapshot(Hatfield360Extension $extension): array
    {
        $pending = (new \ReflectionProperty($extension, 'pending'))->getValue($extension);

        return $pending[array_key_last($pending)][4];
    }
}
