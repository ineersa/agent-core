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
 * path B after removing A still boots agent construction and resolves the
 * configured theme; identity directories differ by canonical path hash.
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

            // Minimal AI-free settings: theme must resolve from bundled themes.
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

            // Boot A: agent --help loads AgentCommand → InteractiveMode → ThemeRegistry.
            // ThemeRegistry constructor ingests config/themes from AppResourceLocator
            // (compiled %kernel.project_dir%). Assert compiled container embeds path A
            // and the bundled cyberpunk theme is readable through that phar:// root.
            $outA = $this->runArtifact($cmd, $pathA, $project, $home, $cacheRoot, ['agent', '--help']);
            $this->assertSame(0, $outA['code'], $outA['combined']);
            $this->assertStringNotContainsString('Theme "cyberpunk" is not registered', $outA['combined']);
            $this->assertStringContainsString('Launch an interactive Hatfield coding-agent session', $outA['combined']);
            $this->assertDirectoryExists($identityA);
            $this->assertCompiledContainerEmbedsPharPath($identityA, $realA);
            $this->assertBundledCyberpunkReadable($realA);

            // Symlink to A must share A's identity (realpath canonicalization).
            $linkPath = $linkDir.'/hatfield-link.phar';
            $this->assertTrue(symlink($pathA, $linkPath));
            $outLink = $this->runArtifact($cmd, $linkPath, $project, $home, $cacheRoot, ['agent', '--help']);
            $this->assertSame(0, $outLink['code'], $outLink['combined']);
            $this->assertDirectoryExists($identityA);
            $this->assertDirectoryDoesNotExist($cacheRoot.'/prod/'.$contentHash.'-'.hash('sha256', $linkPath));

            // Remove A (the original defect path: cache still points at deleted archive).
            // PHP may keep a mapped Phar stream briefly; filesystem unlink is the contract.
            unlink($pathA);
            $this->assertFileDoesNotExist($pathA);

            // Boot B with the same shared cache root: must use identity B and succeed.
            $outB = $this->runArtifact($cmd, $pathB, $project, $home, $cacheRoot, ['agent', '--help']);
            $this->assertSame(0, $outB['code'], $outB['combined']);
            $this->assertStringNotContainsString('Theme "cyberpunk" is not registered', $outB['combined']);
            $this->assertStringNotContainsString('Available themes: (none)', $outB['combined']);
            $this->assertStringContainsString('Launch an interactive Hatfield coding-agent session', $outB['combined']);
            $this->assertDirectoryExists($identityB);
            $this->assertCompiledContainerEmbedsPharPath($identityB, $realB);
            $this->assertStringNotContainsString(
                'phar://'.$realA,
                $this->readCacheTree($identityB),
                'Path B must not reuse compiled wiring for deleted path A',
            );
            $this->assertBundledCyberpunkReadable($realB);

            // Content segment of the identity dir must equal hash_file of the real artifact.
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
     * @param list<string> $cmd
     * @param list<string> $args
     *
     * @return array{code: int, combined: string}
     */
    private function runArtifact(array $cmd, string $artifact, string $cwd, string $home, string $cacheRoot, array $args): array
    {
        // Replace the last command element (artifact path) when the command is
        // [php, phar] or [native]. AgentTestExecutable may point at another copy.
        $launch = $cmd;
        if (\count($launch) >= 2) {
            $launch[\count($launch) - 1] = $artifact;
        } else {
            $launch = [\PHP_BINARY, $artifact];
        }

        $process = new Process(
            array_merge($launch, $args),
            cwd: $cwd,
            env: [
                'HOME' => $home,
                'APP_ENV' => 'prod',
                'APP_DEBUG' => '0',
                'HATFIELD_CACHE_DIR' => $cacheRoot,
                'HATFIELD_LOG_DIR' => $cwd.'/.hatfield/logs',
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            ],
        );
        $process->setTimeout(60.0);
        $process->run();

        return [
            'code' => (int) $process->getExitCode(),
            'combined' => $process->getOutput().$process->getErrorOutput(),
        ];
    }

    private function assertCompiledContainerEmbedsPharPath(string $cacheDir, string $canonicalPharPath): void
    {
        $needle = 'phar://'.$canonicalPharPath;
        $blob = $this->readCacheTree($cacheDir);
        $this->assertStringContainsString(
            $needle,
            $blob,
            'Compiled container under '.$cacheDir.' must embed '.$needle,
        );
    }

    private function assertBundledCyberpunkReadable(string $canonicalPharPath): void
    {
        $theme = 'phar://'.$canonicalPharPath.'/config/themes/cyberpunk.yaml';
        $this->assertFileExists($theme);
        $raw = file_get_contents($theme);
        $this->assertNotFalse($raw);
        $this->assertStringContainsString('name: cyberpunk', $raw);
    }

    private function readCacheTree(string $cacheDir): string
    {
        $blob = '';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $blob .= (string) file_get_contents($file->getPathname());
            }
        }

        return $blob;
    }
}
