<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Strict first-frame render regression for shared MarkdownRenderSupport.
 *
 * Lowest correct layer: mount/render TranscriptMountedWidget under an isolated
 * PHP process with memory_limit=128M. Measured on this fixture:
 * shared create() peaks at 28 MiB; omitting shared dependencies peaks at 72 MiB.
 * The peak budget below is set between those values so removing sharing fails this
 * case. This test does not prove the packaged application's total memory use
 * or reproduce the user session-2 OOM.
 */
final class LargeResumeRenderMemoryTest extends TestCase
{
    public function testLargeMountedTranscriptRendersUnderStrict128M(): void
    {
        $process = new Process(
            [\PHP_BINARY, '-d', 'memory_limit=128M', __DIR__.'/Fixtures/large-resume-render-memory.php'],
            env: ['HATFIELD_SESSION_ID' => false],
            timeout: 8,
        );

        try {
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertSame('128M', $result['limit']);
            $this->assertSame(1350, $result['blocks']);
            $this->assertGreaterThan(500 * 1024, $result['text_sum']);
            $this->assertGreaterThanOrEqual(600, $result['markdown_widgets']);
            $this->assertTrue($result['has_early']);
            $this->assertTrue($result['has_late']);
            // Hard ceiling remains 128M; meaningful sharing regression is ~48 MiB.
            $this->assertLessThan(48 * 1024 * 1024, $result['peak_bytes']);
            fwrite(\STDERR, 'large_resume_render peak_bytes='.$result['peak_bytes']." memory_limit=128M budget=48M\n");
        } finally {
            $process->stop(0);
        }
    }
}
