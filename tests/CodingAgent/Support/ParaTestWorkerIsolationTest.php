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
        $this->assertSame('.hatfield/cache-paraT1', ParaTestWorkerIsolation::cacheDirectory('', '', '1'));
    }

    public function testQaRunWithoutLaneStillScopesByRunAndToken(): void
    {
        $this->assertSame(
            'app_test-qa-run-1-T3.sqlite',
            ParaTestWorkerIsolation::appDatabaseFilename('qa-run-1', '', '3'),
        );
    }

    public function testWorkerHomesPreserveSettingsWithoutSharingMutableSkills(): void
    {
        $template = TestDirectoryIsolation::createProjectTempDir('home-template');
        $homes = [];
        try {
            TestDirectoryIsolation::createHatfieldTree($template);
            $homes[] = ParaTestWorkerIsolation::createHome($template);
            $homes[] = ParaTestWorkerIsolation::createHome($template);
            $this->assertNotSame($homes[0], $homes[1]);
            foreach ($homes as $home) {
                $this->assertSame(file_get_contents($template.'/.hatfield/settings.yaml'), file_get_contents($home.'/.hatfield/settings.yaml'));
                TestDirectoryIsolation::ensureDirectory($home.'/.hatfield/skills/code-review');
                file_put_contents($home.'/.hatfield/skills/code-review/SKILL.md', 'owned by this worker');
            }
            // Replacing one worker's built-ins cannot invalidate another worker's iterator/copy.
            $this->assertTrue(unlink($homes[0].'/.hatfield/skills/code-review/SKILL.md'));
            $this->assertTrue(rmdir($homes[0].'/.hatfield/skills/code-review'));
            $this->assertSame('owned by this worker', file_get_contents($homes[1].'/.hatfield/skills/code-review/SKILL.md'));
            $this->assertFileExists($template.'/.hatfield/settings.yaml');
        } finally {
            foreach ($homes as $home) {
                TestDirectoryIsolation::removeDirectory($home);
            }
            TestDirectoryIsolation::removeDirectory($template);
        }
    }
}
