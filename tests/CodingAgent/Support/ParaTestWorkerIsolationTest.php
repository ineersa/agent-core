<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParaTestWorkerIsolationTest extends TestCase
{
    #[DataProvider('laneCollisionCases')]
    public function testDistinctLanesWithSameTokenDoNotShareDatabaseFiles(
        string $qaRunId,
        string $token,
        string $leftLane,
        string $rightLane,
    ): void {
        $left = ParaTestWorkerIsolation::appDatabaseFilename($qaRunId, $leftLane, $token);
        $right = ParaTestWorkerIsolation::appDatabaseFilename($qaRunId, $rightLane, $token);

        $this->assertNotSame($left, $right);
        $this->assertNotSame(
            ParaTestWorkerIsolation::messengerTransportDatabaseFilename($qaRunId, $leftLane, $token),
            ParaTestWorkerIsolation::messengerTransportDatabaseFilename($qaRunId, $rightLane, $token),
        );
        $this->assertNotSame(
            ParaTestWorkerIsolation::cacheDirectory($qaRunId, $leftLane, $token),
            ParaTestWorkerIsolation::cacheDirectory($qaRunId, $rightLane, $token),
        );
        $this->assertStringContainsString($leftLane, $left);
        $this->assertStringContainsString($rightLane, $right);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function laneCollisionCases(): iterable
    {
        yield 'check unit vs tui' => ['qa-20260830-192019-151232-9db6c7a1', '1', 'unit', 'tui'];
        yield 'check unit vs llm-real' => ['qa-20260830-192019-151232-9db6c7a1', '1', 'unit', 'llm-real'];
        yield 'check tui vs llm-real' => ['qa-20260830-192019-151232-9db6c7a1', '2', 'tui', 'llm-real'];
    }

    public function testMissingLaneKeepsTokenOnlyFallbackForStandalonePools(): void
    {
        $this->assertSame('app_test-T1.sqlite', ParaTestWorkerIsolation::appDatabaseFilename('', '', '1'));
        $this->assertSame(
            'messenger_transport_test-T1.sqlite',
            ParaTestWorkerIsolation::messengerTransportDatabaseFilename('', '', '1'),
        );
        $this->assertSame('.hatfield/cache-paraT1', ParaTestWorkerIsolation::cacheDirectory('', '', '1'));
    }

    public function testQaRunWithoutLaneStillScopesByRunAndToken(): void
    {
        $this->assertSame(
            'app_test-qa-run-1-T3.sqlite',
            ParaTestWorkerIsolation::appDatabaseFilename('qa-run-1', '', '3'),
        );
    }
}
