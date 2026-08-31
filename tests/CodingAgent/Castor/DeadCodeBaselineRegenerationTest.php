<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: dead-code baseline regeneration can replace a baseline that still
 * points at deleted source paths without turning unmatched reporting off for
 * ordinary detector runs, and restores the previous baseline when generation fails.
 */
#[CoversNothing]
final class DeadCodeBaselineRegenerationTest extends TestCase
{
    public function testEmptyBaselineContentsAreValidAndContainNoIgnoreEntries(): void
    {
        self::requireHelpers();

        $contents = \CastorTasks\dead_code_empty_baseline_contents();

        $this->assertStringContainsString('ignoreErrors: []', $contents);
        $this->assertStringNotContainsString('path:', $contents);
    }

    public function testRegenerationRestoresPreviousBaselineWhenCommandFails(): void
    {
        self::requireHelpers();

        $work = TestDirectoryIsolation::createProjectTempDir('dead-code-baseline-regen-');
        $baselinePath = $work.'/phpstan.dead-code-baseline.neon';
        $previous = "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: '#^stale$#'\n\t\t\tidentifier: shipmonk.deadMethod\n\t\t\tcount: 1\n\t\t\tpath: missing/DeletedSource.php\n";

        try {
            $this->assertNotFalse(file_put_contents($baselinePath, $previous));

            $result = \CastorTasks\regenerate_dead_code_baseline_at($baselinePath, 'false', $work);

            $this->assertSame(1, $result['exitCode']);
            $this->assertSame($previous, file_get_contents($baselinePath));
            $this->assertFileDoesNotExist($baselinePath.'.pre-regen');
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    public function testRegenerationKeepsGeneratedBaselineWhenCommandSucceeds(): void
    {
        self::requireHelpers();

        $work = TestDirectoryIsolation::createProjectTempDir('dead-code-baseline-regen-ok-');
        $baselinePath = $work.'/phpstan.dead-code-baseline.neon';
        $generatedPath = $work.'/generated.neon';
        $previous = "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: '#^stale$#'\n\t\t\tidentifier: shipmonk.deadMethod\n\t\t\tcount: 1\n\t\t\tpath: missing/DeletedSource.php\n";
        $generated = "parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: '#^fresh$#'\n\t\t\tidentifier: shipmonk.deadMethod\n\t\t\tcount: 1\n\t\t\tpath: src/Fresh.php\n";

        try {
            $this->assertNotFalse(file_put_contents($baselinePath, $previous));
            $this->assertNotFalse(file_put_contents($generatedPath, $generated));

            $command = \sprintf(
                'cp %s %s',
                escapeshellarg($generatedPath),
                escapeshellarg($baselinePath),
            );
            $result = \CastorTasks\regenerate_dead_code_baseline_at($baselinePath, $command, $work);

            $this->assertSame(0, $result['exitCode']);
            $this->assertSame($generated, file_get_contents($baselinePath));
            $this->assertFileDoesNotExist($baselinePath.'.pre-regen');
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    private static function requireHelpers(): void
    {
        $root = ProjectDir::get();
        $helpersPhp = $root.'/.castor/helpers.php';
        self::assertFileExists($helpersPhp);
        require_once $helpersPhp;
        self::assertTrue(
            \function_exists('CastorTasks\regenerate_dead_code_baseline_at'),
            'regenerate_dead_code_baseline_at must load from .castor/helpers.php',
        );
        self::assertTrue(
            \function_exists('CastorTasks\dead_code_empty_baseline_contents'),
            'dead_code_empty_baseline_contents must load from .castor/helpers.php',
        );
    }
}
