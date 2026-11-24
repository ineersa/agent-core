<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Tests\Support\ForegroundBaselineFixtureProvider;
use Ineersa\Tui\Runtime\TuiRunObservationMapper;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class TuiRunObservationMapperTest extends TestCase
{
    #[DataProviderExternal(ForegroundBaselineFixtureProvider::class, 'malformedForeground')]
    public function testReadyRejectsTheSameMalformedForegroundAsSpool(array $foreground): void
    {
        $this->expectException(ValidationFailedException::class);
        TuiRunObservationMapper::foregroundBaseline(new RuntimeEvent('session.ready', '42', 0,
            ['canonical_seq' => 11, 'foreground' => $foreground]));
    }

    public function testReadyPreservesDuplicateOccurrencesAndByteBoundedMultibyteIds(): void
    {
        $request = ['request_id' => str_repeat('é', 512), 'category' => 'question'];
        $baseline = TuiRunObservationMapper::foregroundBaseline(new RuntimeEvent('session.ready', '42', 0,
            ['canonical_seq' => 11, 'foreground' => ['current_foreground_response_seq' => 11, 'pending_foreground_requests' => [$request, $request]]]));
        $this->assertCount(2, $baseline->pendingRequests);
        $this->assertSame($request['request_id'], $baseline->pendingRequests[0]->requestId);
        $this->assertEquals($baseline->pendingRequests[0], $baseline->pendingRequests[1]);
    }
}
