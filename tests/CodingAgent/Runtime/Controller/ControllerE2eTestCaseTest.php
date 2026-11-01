<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\ControllerE2eTestCase;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class ControllerE2eTestCaseTest extends TestCase
{
    #[TestWith(['wait'])]
    #[TestWith(['type'])]
    #[TestWith(['predicate'])]
    public function testEarlyReturnPreservesOtherFramesFromSameRead(string $collector): void
    {
        $harness = new class('event-collection') extends ControllerE2eTestCase {
            protected function tempDirPrefix(): string
            {
                return 'unused';
            }

            /** @return list<array<string, mixed>> */
            public function collectSingleChunk(string $collector): array
            {
                $pipe = tmpfile();
                $this->assertIsResource($pipe);
                $this->pipes[1] = $pipe;
                try {
                    // All frames are available before collection, without a process or timing race.
                    fwrite($pipe, "{\"type\":\"started\"}\n{\"type\":\"ack\"}\n{\"type\":\"tail\"}\n");
                    rewind($pipe);
                    $first = match ($collector) {
                        'wait' => [$this->waitForEvent('started', 1)],
                        'type' => $this->collectEventsUntil('started', 1),
                        'predicate' => $this->collectEventsUntil(null, 1, static fn (array $event): bool => 'started' === $event['type']),
                    };
                    $ack = $this->waitForEvent('ack', 1);

                    return [...$first, $ack, ...$this->readEvents()];
                } finally {
                    fclose($pipe);
                    $this->pipes = [];
                }
            }
        };

        $this->assertSame(['started', 'ack', 'tail'], array_column($harness->collectSingleChunk($collector), 'type'));
    }
}
