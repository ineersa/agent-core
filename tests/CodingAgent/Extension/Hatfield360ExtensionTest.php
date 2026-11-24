<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Amp\Future;
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
        $pending = (new \ReflectionProperty($extension, 'pending'))->getValue($extension);

        return $pending[array_key_last($pending)][4]['attention'];
    }
}
