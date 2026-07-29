<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Phar;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Regression: compiled Symfony containers embed %kernel.project_dir% as
 * phar://<physical artifact path>. Same bytes relocated must not reuse
 * a container compiled for a deleted path (empty ThemeRegistry / missing
 * cyberpunk after installer temp-path smoke).
 *
 * Thesis: shared explicit cache root + identical PHAR bytes at path A then
 * path B after removing A still constructs InteractiveMode and resolves the
 * configured active theme via ThemeRegistry::getOrThrow().
 *
 * Note: `agent --headless` constructs ThemeRegistry (DI) but never calls
 * getOrThrow() / InteractiveMode::run(), so an empty registry would not fail.
 * The real active-theme path is the TUI entry (`agent`); `--transport=in-process`
 * avoids spawning controller/messenger workers while still calling getOrThrow().
 */
#[Group('phar')]
final class PharArtifactCacheRelocationTest extends TestCase
{
    public function testRelocatedIdenticalPharDoesNotReuseStaleContainerAndResolvesTheme(): void
    {
        [$cmd, $sourcePhar] = $this->resolvePhar();
        $root = TestDirectoryIsolation::createProjectTempDir('phar-reloc');
        $cacheRoot = $root.'/cache-root';
        $project = $root.'/project';
        $home = $root.'/home';
        $binADir = $root.'/bin-a';
        $binBDir = $root.'/bin with spaces';
        $linkDir = $root.'/link-bin';

        try {
            TestDirectoryIsolation::ensureDirectory($cacheRoot);
            TestDirectoryIsolation::createHatfieldTree($project, withSessions: true);
            TestDirectoryIsolation::ensureDirectory($home.'/.hatfield');
            TestDirectoryIsolation::ensureDirectory($binADir);
            TestDirectoryIsolation::ensureDirectory($binBDir);
            TestDirectoryIsolation::ensureDirectory($linkDir);

            // Minimal AI-free settings: active theme must resolve from bundled themes.
            file_put_contents($home.'/.hatfield/settings.yaml', "ai:\n    default_model: null\n");
            file_put_contents($project.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");

            $pathA = $binADir.'/hatfield.phar';
            $pathB = $binBDir.'/hatfield.phar';
            $this->assertTrue(copy($sourcePhar, $pathA), 'copy PHAR to path A');
            $this->assertTrue(copy($sourcePhar, $pathB), 'copy PHAR to path B (spaces)');
            chmod($pathA, 0755);
            chmod($pathB, 0755);

            $contentHash = hash_file('sha256', $pathA);
            $this->assertNotFalse($contentHash);
            $this->assertSame($contentHash, hash_file('sha256', $pathB));

            $realA = realpath($pathA);
            $realB = realpath($pathB);
            $this->assertNotFalse($realA);
            $this->assertNotFalse($realB);
            $this->assertNotSame($realA, $realB);

            $identityA = $cacheRoot.'/prod/'.$contentHash.'-'.hash('sha256', $realA);
            $identityB = $cacheRoot.'/prod/'.$contentHash.'-'.hash('sha256', $realB);
            $this->assertNotSame($identityA, $identityB);

            // Path A: real AgentCommand → InteractiveMode::run → getOrThrow('cyberpunk').
            $outA = $this->runAgentUntilThemeResolved($cmd, $pathA, $project, $home, $cacheRoot);
            $this->assertThemeBootSucceeded($outA);
            $this->assertDirectoryExists($identityA);

            // Symlink to A must share A's identity (realpath canonicalization).
            $linkPath = $linkDir.'/hatfield-link.phar';
            $this->assertTrue(symlink($pathA, $linkPath));
            $outLink = $this->runAgentUntilThemeResolved($cmd, $linkPath, $project, $home, $cacheRoot);
            $this->assertThemeBootSucceeded($outLink);
            $this->assertDirectoryExists($identityA);
            $this->assertDirectoryDoesNotExist($cacheRoot.'/prod/'.$contentHash.'-'.hash('sha256', $linkPath));

            // Remove A (original defect: cache still points at deleted archive).
            unlink($pathA);
            $this->assertFileDoesNotExist($pathA);

            // Path B with same shared cache root: must use identity B and resolve theme.
            $outB = $this->runAgentUntilThemeResolved($cmd, $pathB, $project, $home, $cacheRoot);
            $this->assertThemeBootSucceeded($outB);
            $this->assertDirectoryExists($identityB);
            $this->assertStringContainsString('/'.$contentHash.'-', $identityB);
        } finally {
            TestDirectoryIsolation::removeDirectory($root);
        }
    }

    /**
     * @return array{0: list<string>, 1: string}
     */
    private function resolvePhar(): array
    {
        $cmd = AgentTestExecutable::command();
        $path = AgentTestExecutable::path();
        if (!str_ends_with($path, '.phar') || !is_file($path)) {
            $this->markTestSkipped(
                'Requires HATFIELD_BINARY_PATH pointing at a built hatfield.phar (castor phar:build).'
            );
        }

        return [$cmd, $path];
    }

    /**
     * Run agent TUI construction with in-process transport (no controller workers).
     *
     * Closes stdin, waits until InteractiveMode has resolved the active theme
     * (cyberpunk logo paint), then SIGTERM for a clean exit(0).
     *
     * @param list<string> $cmd
     *
     * @return array{code: int, combined: string}
     */
    private function runAgentUntilThemeResolved(array $cmd, string $artifact, string $cwd, string $home, string $cacheRoot): array
    {
        $launch = $cmd;
        if (\count($launch) >= 2) {
            $launch[\count($launch) - 1] = $artifact;
        } else {
            $launch = [\PHP_BINARY, $artifact];
        }

        $process = new Process(
            array_merge($launch, ['agent', '--transport=in-process']),
            cwd: $cwd,
            env: [
                'HOME' => $home,
                'APP_ENV' => 'prod',
                'APP_DEBUG' => '0',
                'HATFIELD_CACHE_DIR' => $cacheRoot,
                'HATFIELD_LOG_DIR' => $cwd.'/.hatfield/logs',
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                // Avoid inheriting a host terminal that keeps the TUI alive longer.
                'TERM' => 'dumb',
            ],
        );
        $process->setTimeout(15.0);
        $process->setInput('');
        $process->start();

        $deadline = microtime(true) + 8.0;
        $combined = '';
        while (microtime(true) < $deadline) {
            $combined = $process->getOutput().$process->getErrorOutput();
            // Cyberpunk logo paint proves getOrThrow('cyberpunk') succeeded and
            // InteractiveMode entered the TUI session loop.
            if (str_contains($combined, 'HATFIELD') || str_contains($combined, '██')) {
                break;
            }
            if (!$process->isRunning()) {
                break;
            }
            usleep(50_000);
        }

        if ($process->isRunning()) {
            // InteractiveMode SIGTERM handler exits 0 after theme resolution.
            $process->stop(3.0);
        }

        $combined = $process->getOutput().$process->getErrorOutput();

        return [
            'code' => (int) $process->getExitCode(),
            'combined' => $combined,
        ];
    }

    /**
     * @param array{code: int, combined: string} $out
     */
    private function assertThemeBootSucceeded(array $out): void
    {
        $this->assertStringNotContainsString(
            'Theme "cyberpunk" is not registered',
            $out['combined'],
            $out['combined'],
        );
        $this->assertStringNotContainsString(
            'Available themes: (none)',
            $out['combined'],
            $out['combined'],
        );
        $this->assertTrue(
            str_contains($out['combined'], 'HATFIELD') || str_contains($out['combined'], '██'),
            "Expected cyberpunk TUI paint after getOrThrow; output:\n".$out['combined'],
        );
        // InteractiveMode SIGTERM handler exits 0; Process::stop SIGTERM is 143.
        $this->assertContains(
            $out['code'],
            [0, 143],
            'Expected clean stop after theme resolution (0 / SIGTERM). Output: '.$out['combined'],
        );
    }
}
