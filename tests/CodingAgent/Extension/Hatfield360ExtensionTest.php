<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Amp\Future;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiForegroundBaselineDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiForegroundRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiRunObservationDTO;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObservationTypeEnum as Observation;
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
        $extension->onTuiForegroundBaseline(new TuiForegroundBaselineDTO('42', 4, 1, [new TuiForegroundRequestDTO('same', Observation::ApprovalRequested)]));
        $this->assertSame($second, $this->attention($extension));
    }

    public function testDuplicateOccurrencesResolveFifoAndReconcileStableEpisodes(): void
    {
        $extension = $this->extension();
        $baseline = new TuiForegroundBaselineDTO('42', 10, 1, [
            new TuiForegroundRequestDTO('same', Observation::QuestionRequested),
            new TuiForegroundRequestDTO('same', Observation::ApprovalRequested),
        ]);
        $extension->onTuiForegroundBaseline($baseline);
        $first = $this->attention($extension);
        $extension->onTuiForegroundBaseline($baseline);
        $this->assertSame($first, $this->attention($extension));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 11, Observation::InputResolved, 'same'));
        $second = $this->attention($extension);
        $this->assertSame('permission', $second['reason']);
        $this->assertNotSame($first['id'], $second['id']);
        $extension->onTuiForegroundBaseline(new TuiForegroundBaselineDTO('42', 11, 1, [$baseline->pendingRequests[1]]));
        $this->assertSame($second, $this->attention($extension));
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 12, Observation::InputResolved, 'same'));
        $this->assertNull($this->attention($extension));
    }

    public function testSeventeenBaselineRequestsStillNeedAttentionAfterSixteenAnswers(): void
    {
        $extension = $this->extension();
        $requests = [];
        for ($i = 0; $i < 17; ++$i) {
            $requests[] = new TuiForegroundRequestDTO((string) $i, Observation::QuestionRequested);
        }
        $extension->onTuiForegroundBaseline(new TuiForegroundBaselineDTO('42', 20, 1, $requests));
        for ($i = 0; $i < 16; ++$i) {
            $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 21 + $i, Observation::InputResolved, (string) $i));
        }
        $this->assertSame('question', $this->attention($extension)['reason']);
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
        $this->assertNotNull($this->attention($extension), 'Overflow remains unknown until authoritative reconciliation.');
        $extension->onTuiRunObserved(new TuiRunObservationDTO('42', 1, Observation::ApprovalRequested, 'child-question', 'child'));
        $extension->onTuiForegroundBaseline(new TuiForegroundBaselineDTO('42', 6000, null, []));
        $this->assertSame('permission', $this->attention($extension)['reason']);
    }

    private function extension(): Hatfield360Extension
    {
        $extension = new Hatfield360Extension();
        (new \ReflectionProperty($extension, 'sessionId'))->setValue($extension, '42');
        // Hold the outbound queue locally: exercise real observation/reconciliation
        // without registration, credentials, sockets, or an asynchronous delivery race.
        (new \ReflectionProperty($extension, 'drain'))->setValue($extension, Future::complete());

        return $extension;
    }

    /** @return array{id: string, reason: string}|null */
    private function attention(Hatfield360Extension $extension): ?array
    {
        $pending = (new \ReflectionProperty($extension, 'pending'))->getValue($extension);

        return $pending[array_key_last($pending)][4]['attention'];
    }
}
