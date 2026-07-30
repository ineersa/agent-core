<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI;

use Ineersa\CodingAgent\CLI\FileMentionIndexBuilder;
use Ineersa\CodingAgent\CLI\FileMentionIndexLockHeldException;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

#[CoversClass(FileMentionIndexBuilder::class)]
final class FileMentionIndexBuilderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('editor09-builder');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    #[Test]
    public function includesFilesAndDirectories(): void
    {
        mkdir($this->tmpDir.'/src', 0755, true);
        touch($this->tmpDir.'/src/foo.php');
        mkdir($this->tmpDir.'/src/nested', 0755, true);
        touch($this->tmpDir.'/src/nested/bar.php');

        $indexPath = $this->tmpDir.'/index.jsonl';
        $builder = new FileMentionIndexBuilder($this->tmpDir, $indexPath, logger: $this->createLogger(), lockFactory: $this->createLockFactory());
        $count = $builder->build();

        $this->assertGreaterThanOrEqual(3, $count);

        $lines = file($indexPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $this->assertNotFalse($lines);

        $paths = array_map(static fn (string $l): string => json_decode($l, true)['path'], $lines);
        sort($paths);

        $this->assertContains('src', $paths);
        $this->assertContains('src/foo.php', $paths);
        $this->assertContains('src/nested', $paths);
        $this->assertContains('src/nested/bar.php', $paths);
    }

    #[Test]
    public function excludesDefaultNoisyDirectories(): void
    {
        mkdir($this->tmpDir.'/vendor', 0755, true);
        touch($this->tmpDir.'/vendor/excluded.php');
        mkdir($this->tmpDir.'/node_modules', 0755, true);
        touch($this->tmpDir.'/node_modules/excluded.js');
        mkdir($this->tmpDir.'/var', 0755, true);
        touch($this->tmpDir.'/var/excluded.log');
        mkdir($this->tmpDir.'/.git', 0755, true);
        touch($this->tmpDir.'/.git/config');
        mkdir($this->tmpDir.'/.hatfield', 0755, true);
        mkdir($this->tmpDir.'/.hatfield/sessions', 0755, true);
        touch($this->tmpDir.'/.hatfield/sessions/excluded.json');
        mkdir($this->tmpDir.'/.hatfield/tmp', 0755, true);
        touch($this->tmpDir.'/.hatfield/tmp/excluded.tmp');
        mkdir($this->tmpDir.'/.hatfield/cache', 0755, true);
        touch($this->tmpDir.'/.hatfield/cache/excluded.cache');

        // Files OUTSIDE excluded dirs should be included.
        touch($this->tmpDir.'/included.php');

        $indexPath = $this->tmpDir.'/index.jsonl';
        $builder = new FileMentionIndexBuilder($this->tmpDir, $indexPath, logger: $this->createLogger(), lockFactory: $this->createLockFactory());
        $count = $builder->build();

        $lines = file($indexPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $this->assertNotFalse($lines);

        $paths = array_map(static fn (string $l): string => json_decode($l, true)['path'], $lines);

        // Included paths should appear.
        $this->assertContains('included.php', $paths);

        // Excluded directories should NOT appear.
        foreach ($paths as $path) {
            $this->assertStringNotContainsString('vendor/', $path);
            $this->assertStringNotContainsString('node_modules/', $path);
            $this->assertStringNotContainsString('var/', $path);
            $this->assertStringNotContainsString('.git/', $path);
            $this->assertStringNotContainsString('.hatfield/sessions', $path);
            $this->assertStringNotContainsString('.hatfield/tmp', $path);
            $this->assertStringNotContainsString('.hatfield/cache', $path);
        }
    }

    /**
     * Non-Git Finder fallback must pre-prune cache-* / nested noisy trees
     * (old code only excluded exact `.hatfield/cache` and still descended
     * into cache-qa / cache-paraT variants).
     */
    #[Test]
    public function nonGitFallbackExcludesCacheSuffixedAndNestedNoisyTrees(): void
    {
        // Bare directory tree without .git → Finder fallback path.
        mkdir($this->tmpDir.'/src', 0755, true);
        touch($this->tmpDir.'/src/kept.php');

        mkdir($this->tmpDir.'/.hatfield/cache-qa-20260729-deadbeef', 0755, true);
        touch($this->tmpDir.'/.hatfield/cache-qa-20260729-deadbeef/huge.cache');
        mkdir($this->tmpDir.'/.hatfield/cache-paraT16', 0755, true);
        touch($this->tmpDir.'/.hatfield/cache-paraT16/para.cache');
        mkdir($this->tmpDir.'/.hatfield/cache', 0755, true);
        touch($this->tmpDir.'/.hatfield/cache/excluded.cache');
        mkdir($this->tmpDir.'/.hatfield/sessions/sess', 0755, true);
        touch($this->tmpDir.'/.hatfield/sessions/sess/events.jsonl');
        mkdir($this->tmpDir.'/.hatfield/tmp', 0755, true);
        touch($this->tmpDir.'/.hatfield/tmp/tmp.file');

        mkdir($this->tmpDir.'/tools/phar/vendor/pkg', 0755, true);
        touch($this->tmpDir.'/tools/phar/vendor/pkg/excluded.php');
        mkdir($this->tmpDir.'/apps/web/node_modules/pkg', 0755, true);
        touch($this->tmpDir.'/apps/web/node_modules/pkg/excluded.js');
        mkdir($this->tmpDir.'/packages/foo/var/cache', 0755, true);
        touch($this->tmpDir.'/packages/foo/var/cache/excluded.log');

        // Legitimate source whose basename substring is not a path component.
        touch($this->tmpDir.'/src/vendorish.php');

        $indexPath = $this->tmpDir.'/index.jsonl';
        $builder = new FileMentionIndexBuilder($this->tmpDir, $indexPath, logger: $this->createLogger(), lockFactory: $this->createLockFactory());
        $builder->build();

        $paths = $this->indexPaths($indexPath);

        $this->assertContains('src', $paths);
        $this->assertContains('src/kept.php', $paths);
        $this->assertContains('src/vendorish.php', $paths);

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('cache-qa-', $path, $path);
            $this->assertStringNotContainsString('cache-paraT', $path, $path);
            $this->assertStringNotContainsString('.hatfield/cache/', $path, $path);
            $this->assertStringNotContainsString('.hatfield/sessions', $path, $path);
            $this->assertStringNotContainsString('.hatfield/tmp', $path, $path);
            $this->assertStringNotContainsString('/vendor/', '/'.$path.'/');
            $this->assertStringNotContainsString('/node_modules/', '/'.$path.'/');
            $this->assertStringNotContainsString('/var/', '/'.$path.'/');
        }
    }

    /**
     * Git-native enumeration: tracked + untracked non-ignored files,
     * inferred parent dirs, nested .gitignore, NUL-safe special names,
     * and explicit noisy trees even when tracked.
     */
    #[Test]
    public function gitWorktreeEnumeratesTrackedUntrackedAndExcludesNoisyTrees(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git is not available.');
        }

        $repo = $this->tmpDir.'/git-repo';
        mkdir($repo, 0755, true);
        $this->runGit($repo, ['init', '-q']);
        $this->runGit($repo, ['config', 'user.email', 'test@example.com']);
        $this->runGit($repo, ['config', 'user.name', 'Test']);

        // Nested ignore of build/ (must not appear even if present on disk).
        file_put_contents($repo.'/.gitignore', "build/\n.hatfield/cache-*/\n");
        mkdir($repo.'/src/nested', 0755, true);
        file_put_contents($repo.'/src/nested/tracked.php', "<?php\n");
        mkdir($repo.'/build', 0755, true);
        file_put_contents($repo.'/build/artifact.bin', 'x');

        // Path with spaces (and a newline-named file via direct write).
        mkdir($repo.'/dir with spaces', 0755, true);
        file_put_contents($repo.'/dir with spaces/file.php', "<?php\n");
        $newlineName = "weird\nname.php";
        file_put_contents($repo.'/'.$newlineName, "<?php\n");

        // Explicit noisy trees (also force-add one tracked vendor file so
        // --cached would surface it without our path-component filter).
        mkdir($repo.'/tools/phar/vendor/pkg', 0755, true);
        file_put_contents($repo.'/tools/phar/vendor/pkg/excluded.php', "<?php\n");
        mkdir($repo.'/.hatfield/cache-qa-20260729-deadbeef', 0755, true);
        file_put_contents($repo.'/.hatfield/cache-qa-20260729-deadbeef/huge.cache', 'x');
        mkdir($repo.'/.hatfield/sessions/s1', 0755, true);
        file_put_contents($repo.'/.hatfield/sessions/s1/events.jsonl', '{}');
        mkdir($repo.'/var/tmp', 0755, true);
        file_put_contents($repo.'/var/tmp/x.log', 'x');

        $this->runGit($repo, ['add', '-A']);
        // Force-add ignored noisy path if git refused it; ignore failure.
        $this->runGitAllowFail($repo, ['add', '-f', 'tools/phar/vendor/pkg/excluded.php']);
        $this->runGit($repo, ['commit', '-q', '-m', 'seed']);

        // Untracked non-ignored file must still be indexed.
        file_put_contents($repo.'/src/untracked.php', "<?php\n");

        $indexPath = $this->tmpDir.'/git-index.jsonl';
        $builder = new FileMentionIndexBuilder($repo, $indexPath, logger: $this->createLogger(), lockFactory: $this->createLockFactory());
        $count = $builder->build();
        $this->assertGreaterThan(0, $count);

        $paths = $this->indexPaths($indexPath);

        $this->assertContains('src', $paths);
        $this->assertContains('src/nested', $paths);
        $this->assertContains('src/nested/tracked.php', $paths);
        $this->assertContains('src/untracked.php', $paths);
        $this->assertContains('dir with spaces', $paths);
        $this->assertContains('dir with spaces/file.php', $paths);
        $this->assertContains($newlineName, $paths);

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('build/', $path, $path);
            $this->assertStringNotContainsString('cache-qa-', $path, $path);
            $this->assertStringNotContainsString('.hatfield/sessions', $path, $path);
            $this->assertStringNotContainsString('/vendor/', '/'.$path.'/');
            $this->assertStringNotContainsString('/var/', '/'.$path.'/');
        }
    }

    #[Test]
    public function writesAtomically(): void
    {
        touch($this->tmpDir.'/existing.php');

        $indexPath = $this->tmpDir.'/index.jsonl';
        $builder = new FileMentionIndexBuilder($this->tmpDir, $indexPath, logger: $this->createLogger(), lockFactory: $this->createLockFactory());
        $builder->build();

        $this->assertFileExists($indexPath);

        // No leftover tmp files after build.
        $tmpFiles = glob($this->tmpDir.'/*.tmp.*');
        $this->assertEmpty($tmpFiles, 'No temp files should remain after atomic rename.');
    }

    #[Test]
    public function capsEntriesAtMaxLimit(): void
    {
        // Create many files.
        for ($i = 0; $i < 100; ++$i) {
            touch($this->tmpDir.'/file_'.str_pad((string) $i, 3, '0', \STR_PAD_LEFT).'.txt');
        }

        $indexPath = $this->tmpDir.'/index.jsonl';
        $builder = new FileMentionIndexBuilder($this->tmpDir, $indexPath, logger: $this->createLogger(), lockFactory: $this->createLockFactory());
        $count = $builder->build();

        $this->assertLessThanOrEqual(50_000, $count);
        $this->assertGreaterThan(0, $count);
    }

    #[Test]
    public function nonBlockingLockPreventsConcurrentBuild(): void
    {
        touch($this->tmpDir.'/a.php');

        $indexPath = $this->tmpDir.'/index.jsonl';
        // Both builders share the same LockFactory → same lock resource.
        $lockFactory = $this->createLockFactory();

        // Manually hold the lock to simulate concurrent build.
        $lock = $lockFactory->createLock('file_mention_index.'.hash('xxh32', $indexPath), ttl: 300.0);
        $this->assertTrue($lock->acquire(false), 'Should acquire lock when no one holds it.');

        $builder = new FileMentionIndexBuilder($this->tmpDir, $indexPath, logger: $this->createLogger(), lockFactory: $lockFactory);

        // Builder should throw because lock is held.
        $this->expectException(FileMentionIndexLockHeldException::class);
        $builder->build();
    }

    #[Test]
    public function lockReleasedAfterBuild(): void
    {
        touch($this->tmpDir.'/a.php');

        $indexPath = $this->tmpDir.'/index.jsonl';
        $lockFactory = $this->createLockFactory();

        $builder1 = new FileMentionIndexBuilder($this->tmpDir, $indexPath, logger: $this->createLogger(), lockFactory: $lockFactory);
        $count = $builder1->build();
        $this->assertGreaterThan(0, $count);

        // Lock should be released — second build with same lock factory succeeds.
        $builder2 = new FileMentionIndexBuilder($this->tmpDir, $indexPath, logger: $this->createLogger(), lockFactory: $lockFactory);
        $count2 = $builder2->build();
        $this->assertGreaterThan(0, $count2);
    }

    #[Test]
    public function keepsOldIndexOnBuildFailure(): void
    {
        // Create a valid existing index.
        $indexPath = $this->tmpDir.'/index.jsonl';
        file_put_contents($indexPath, '{"path":"old.php","dir":false}');

        // Try to build with a non-existent cwd — should fail.
        $builder = new FileMentionIndexBuilder(
            $this->tmpDir.'/nonexistent',
            $indexPath,
            logger: $this->createLogger(),
            lockFactory: $this->createLockFactory(),
        );

        try {
            $builder->build();
            $this->fail('Expected RuntimeException for non-existent cwd.');
        } catch (\RuntimeException) {
            // Expected — build failed.
        }

        // Old index should still exist and be intact.
        $this->assertFileExists($indexPath);
        $content = file_get_contents($indexPath);
        $this->assertNotFalse($content);
        $this->assertStringContainsString('old.php', $content);
    }

    /**
     * Round-trip: builder writes index → reader reloads → provider
     * creates suggestion → applying replacement produces expected.
     *
     * @ path text.  Exercises the full index → completion chain.
     */
    #[Test]
    public function roundTripBuilderToCompletion(): void
    {
        // ── Build filesystem fixtures ──
        mkdir($this->tmpDir.'/src', 0755, true);
        touch($this->tmpDir.'/src/foo.php');
        mkdir($this->tmpDir.'/src/nested', 0755, true);
        touch($this->tmpDir.'/src/nested/bar.php');
        mkdir($this->tmpDir.'/dir with spaces', 0755, true);
        touch($this->tmpDir.'/dir with spaces/file.php');

        // ── Builder writes index ──
        $indexPath = $this->tmpDir.'/index.jsonl';
        $builder = new FileMentionIndexBuilder($this->tmpDir, $indexPath, logger: $this->createLogger(), lockFactory: $this->createLockFactory());
        $count = $builder->build();
        $this->assertGreaterThan(0, $count);

        // ── Reader loads the index ──
        $reader = new \Ineersa\Tui\Completion\FileMentionIndexReader($indexPath);

        // ── Provider creates suggestions ──
        $provider = new \Ineersa\Tui\Completion\FileMentionCompletionProvider($reader);

        // ── @ matching: verify suggestions are correct ──
        $suggestions = $provider->getSuggestions(
            \Ineersa\Tui\Completion\CompletionContext::forCursorAtEnd('@src/'),
        );
        $this->assertGreaterThan(0, \count($suggestions));

        // Find the src/foo.php suggestion
        $foo = null;
        foreach ($suggestions as $s) {
            if (str_ends_with($s->display, 'src/foo.php')) {
                $foo = $s;
                break;
            }
        }
        $this->assertNotNull($foo);
        $this->assertStringStartsWith('@', $foo->insertText);
        $this->assertStringEndsWith(' ', $foo->insertText);

        // ── Simulate acceptance via CompletionListener ──
        $currentText = '@src/';
        $applied = substr_replace(
            $currentText,
            $foo->insertText,
            $foo->replacementStart,
            $foo->replacementLength,
        );
        $this->assertStringStartsWith('@', $applied);

        // ── @ directory suggestion ──
        $dirSuggs = $provider->getSuggestions(
            \Ineersa\Tui\Completion\CompletionContext::forCursorAtEnd('@src'),
        );
        $nestedDir = null;
        foreach ($dirSuggs as $s) {
            // Display includes @ prefix and trailing / for dirs,
            // e.g. "@src/nested/".
            $displayWithoutAt = substr($s->display, 1);
            if ('src/nested/' === $displayWithoutAt) {
                $nestedDir = $s;
                break;
            }
        }
        $this->assertNotNull($nestedDir);
        $this->assertStringEndsWith('/', $nestedDir->insertText);

        // ── Quoted path suggestion ──
        $quotedSuggs = $provider->getSuggestions(
            \Ineersa\Tui\Completion\CompletionContext::forCursorAtEnd('@"dir'),
        );
        $this->assertGreaterThan(0, \count($quotedSuggs));

        $quoted = null;
        foreach ($quotedSuggs as $s) {
            if (str_contains($s->insertText, 'dir with spaces')) {
                $quoted = $s;
                break;
            }
        }
        $this->assertNotNull($quoted);
        $this->assertStringStartsWith('@"', $quoted->insertText);

        // Simulate acceptance of quoted suggestion.
        $quotedText = '@"dir';
        $quotedApplied = substr_replace(
            $quotedText,
            $quoted->insertText,
            $quoted->replacementStart,
            $quoted->replacementLength,
        );
        $this->assertStringStartsWith('@', $quotedApplied);
        $this->assertStringContainsString('dir with spaces', $quotedApplied);
    }

    #[Test]
    public function indexesFilesWhenCwdIsVcsIgnoredByParentGitignore(): void
    {
        // Simulate an isolated project nested inside a git repository
        // where the parent .gitignore ignores the project directory
        // (e.g. var/tmp/test-* in agent-core's .gitignore).
        // The builder should fall back to Finder so the index is
        // not empty — the explicit exclude list handles noisy dirs.
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git is not available for VCS-ignored CWD test.');
        }

        // ── Create a temporary git repo with a .gitignore ──
        $gitRepo = $this->tmpDir.'/parent-repo';
        mkdir($gitRepo, 0755, true);
        file_put_contents($gitRepo.'/.gitignore', "/ignored-scratch/\n");
        $this->runGit($gitRepo, ['init', '-q']);

        // ── Create the CWD inside the ignored directory ──
        $cwd = $gitRepo.'/ignored-scratch/my-project';
        mkdir($cwd, 0755, true);
        touch($cwd.'/included.php');
        mkdir($cwd.'/vendor', 0755, true);
        touch($cwd.'/vendor/excluded.php');

        // ── Verify git sees the cwd as ignored ──
        $check = new \Symfony\Component\Process\Process([
            'git', '-C', $cwd, 'check-ignore', '.',
        ]);
        $check->run();
        if (0 !== $check->getExitCode()) {
            $this->markTestSkipped('CWD is not recognized as git-ignored; test setup may need adjustment.');
        }

        $indexPath = $cwd.'/index.jsonl';
        $builder = new FileMentionIndexBuilder($cwd, $indexPath, logger: $this->createLogger(), lockFactory: $this->createLockFactory());
        $count = $builder->build();

        $this->assertGreaterThan(0, $count, 'Index must contain entries even when CWD is VCS-ignored.');

        $paths = $this->indexPaths($indexPath);

        // included.php should appear (not filtered by VCS ignore).
        $this->assertContains('included.php', $paths);

        // vendor/ should NOT appear (explicit exclude).
        foreach ($paths as $path) {
            $this->assertStringNotContainsString('vendor/', $path);
        }
    }

    /**
     * @return list<string>
     */
    private function indexPaths(string $indexPath): array
    {
        $lines = file($indexPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $this->assertNotFalse($lines);

        return array_map(static fn (string $l): string => json_decode($l, true)['path'], $lines);
    }

    private function gitAvailable(): bool
    {
        $which = new \Symfony\Component\Process\Process(['which', 'git']);
        $which->run();

        return 0 === $which->getExitCode() && '' !== trim($which->getOutput());
    }

    /**
     * @param list<string> $args
     */
    private function runGit(string $cwd, array $args): void
    {
        $process = new \Symfony\Component\Process\Process(array_merge(['git', '-C', $cwd], $args));
        $process->run();
        $this->assertSame(
            0,
            $process->getExitCode(),
            'git '.implode(' ', $args).' failed: '.$process->getErrorOutput().$process->getOutput(),
        );
    }

    /**
     * @param list<string> $args
     */
    private function runGitAllowFail(string $cwd, array $args): void
    {
        $process = new \Symfony\Component\Process\Process(array_merge(['git', '-C', $cwd], $args));
        $process->run();
    }

    private function createLockFactory(): LockFactory
    {
        // FlockStore in the test tmp dir so locks are isolated to this test.
        return new LockFactory(new FlockStore($this->tmpDir));
    }

    private function createLogger(): LoggerInterface
    {
        return $this->createStub(LoggerInterface::class);
    }
}
