<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Phar;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Built PHAR smoke test.
 *
 * Validates that the PHAR exists (e.g. `var/tmp/phar/hatfield.phar` when
 * HATFIELD_BINARY_PATH is set by Castor) and that it boots sufficiently to
 * respond to `php hatfield.phar list` with the expected agent command.
 *
 * This test is NOT in the llm-real group because it does not need
 * llama.cpp. It is not in any group by default — run it explicitly:
 *
 *   HATFIELD_BINARY_PATH=var/tmp/phar/hatfield.phar vendor/bin/phpunit --filter PharSmokeTest
 *
 * Or through Castor:
 *
 *   castor phar:build && HATFIELD_BINARY_PATH=var/tmp/phar/hatfield.phar vendor/bin/phpunit --filter PharSmokeTest
 */
#[Group('phar')]
final class PharSmokeTest extends TestCase
{
    /**
     * Default project-relative PHAR path used in skip messages.
     *
     * The actual path is resolved via HATFIELD_BINARY_PATH env var
     * (set by Castor tasks) or AgentTestExecutable.  This constant mirrors
     * the build default from .castor/helpers.php:hatfield_phar_path().
     * Castor resolves this relative to the project root so each worktree
     * gets its own local PHAR.
     */
    private const string DEFAULT_PHAR_PATH = 'var/tmp/phar/hatfield.phar';
    /** @var list<string> */
    private array $isolatedHomeDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->isolatedHomeDirs as $dir) {
            TestDirectoryIsolation::removeDirectory($dir);
        }
        $this->isolatedHomeDirs = [];
    }

    public function testPharBootingToAgentList(): void
    {
        [$cmd, $pharPath] = $this->resolveArtifactCommand();
        $isPhar = str_ends_with($pharPath, '.phar');

        if (!$isPhar) {
            $this->markTestSkipped(\sprintf(
                'HATFIELD_BINARY_PATH not set or not a PHAR. Resolved to %s. '
                .'Run: castor phar:build && HATFIELD_BINARY_PATH=var/tmp/phar/hatfield.phar vendor/bin/phpunit --filter PharSmokeTest',
                $pharPath,
            ));
        }

        $this->assertFileExists($pharPath, 'PHAR not found at '.$pharPath);
        $this->assertFileIsReadable($pharPath);

        // PHAR is a production artifact — never inherit APP_ENV=test from
        // the PHPUnit parent process (which would trigger
        // Class-not-found for test-only bundles like DAMADoctrineTestBundle).
        $output = $this->shellExecIsolated('APP_ENV=prod '.$this->shellCommand($cmd, 'list 2>&1'));
        $this->assertNotNull($output, 'PHAR list command produced no output');
        $this->assertStringContainsString('agent', $output, 'PHAR list output should contain the agent command');

        $sizeMb = filesize($pharPath) / 1024 / 1024;
        $this->assertLessThan(
            20.0,
            $sizeMb,
            \sprintf('PHAR size %.1f MB exceeds 20 MB limit', $sizeMb),
        );

        echo \sprintf("\nPHAR smoke test ok: %s (%.1f MB)\n", $pharPath, $sizeMb);
    }

    public function testPharVersionIdentity(): void
    {
        [$cmd, $pharPath] = $this->resolveArtifactCommand();
        $isPhar = str_ends_with($pharPath, '.phar');

        if (!$isPhar) {
            $this->markTestSkipped(\sprintf(
                'HATFIELD_BINARY_PATH not set or not a PHAR. Resolved to %s.',
                $pharPath,
            ));
        }

        $output = $this->shellExecIsolated('APP_ENV=prod '.$this->shellCommand($cmd, '--version 2>&1'));
        $this->assertNotNull($output);
        $this->assertStringContainsString('Hatfield', $output);
        $this->assertStringContainsString('commit', $output);
    }

    public function testPharAgentHelp(): void
    {
        [$cmd, $pharPath] = $this->resolveArtifactCommand();
        $isPhar = str_ends_with($pharPath, '.phar');

        if (!$isPhar) {
            $this->markTestSkipped(\sprintf(
                'HATFIELD_BINARY_PATH not set or not a PHAR. Resolved to %s. '
                .'Run: castor phar:build && HATFIELD_BINARY_PATH=var/tmp/phar/hatfield.phar vendor/bin/phpunit --filter PharSmokeTest',
                $pharPath,
            ));
        }

        // Also verify that --help works on the agent command.
        // APP_ENV=prod prevents the PHAR from trying to load test-only
        // bundles (DAMADoctrineTestBundle) inherited from the PHPUnit env.
        $output = $this->shellExecIsolated('APP_ENV=prod '.$this->shellCommand($cmd, 'agent --help 2>&1'));
        $this->assertNotNull($output, 'PHAR agent --help produced no output');
        $this->assertStringContainsString('Usage:', $output);
    }

    /**
     * Verify the PHAR boots correctly from the repo root where a source-tree
     * vendor/ directory exists alongside the PHAR's bundled vendor.
     *
     * When APP_ENV=dev is inherited from Castor's .env loading, a stale
     * source-checkout dev cache (kernel.project_dir pointing to filesystem
     * paths) would be reused by the PHAR, causing Cannot-redeclare-class
     * collisions between the PHAR's autoloader and source-tree vendor files.
     *
     * Cache isolation (PHAR-specific hash suffix on cache dirs) prevents
     * this. This test explicitly runs the PHAR from the repo root to catch
     * regressions.
     */
    #[Group('phar')]
    public function testPharRunsFromRepoRootWithSourceTreeVendor(): void
    {
        [$cmd, $pharPath] = $this->resolveArtifactCommand();
        $isPhar = str_ends_with($pharPath, '.phar');

        if (!$isPhar) {
            $this->markTestSkipped('Not running as PHAR — requires HATFIELD_BINARY_PATH pointing to built hatfield.phar');
        }

        // Run from repo root (where source-tree vendor/ is visible).
        // If cache isolation is broken, this triggers autoloader collisions.
        // Force APP_ENV=prod — the PHAR is a production artifact without dev
        // bundles. Inheriting APP_ENV=test from PHPUnit would cause
        // Class-not-found errors for test-only bundles like DAMADoctrineTestBundle.
        $repoRoot = \dirname(__DIR__, 3);
        $isolatedHome = $this->createIsolatedHome();
        $process = Process::fromShellCommandline(
            \sprintf('HOME=%s APP_ENV=prod HATFIELD_CACHE_DIR= %s', escapeshellarg($isolatedHome), $this->shellCommand($cmd, 'list')),
            cwd: $repoRoot,
        );
        $process->mustRun();

        $output = $process->getOutput();
        $this->assertStringContainsString('agent', $output, 'PHAR list must contain agent command when run from repo root');
    }

    /**
     * Verify PHAR cache isolation uses a content-based hash of the archive
     * file, not a stable __FILE__ fixpoint that allows stale Symfony
     * compiled containers to survive PHAR rebuilds.
     *
     * The regression caught by this test: the old code computed
     *   substr(md5(__FILE__), 0, 8)
     * which inside a PHAR is a stable 8-char hex string across rebuilds.
     * When any service constructor changed (e.g. TickPollListener gained
     * arguments on SAFE-04), the stale container from a previous PHAR
     * build was reused and threw ArgumentCountError on boot.
     *
     * The fix uses hash_file('sha256', $pharPath) to produce a 12-char
     * content-based suffix.  This test validates both the suffix length
     * (12 not 8) and format (lowercase hex) as a cheap regression guard.
     */
    #[Group('phar')]
    public function testPharCacheIsolationUsesContentHash(): void
    {
        [$cmd, $pharPath] = $this->resolveArtifactCommand();
        $isPhar = str_ends_with($pharPath, '.phar');

        if (!$isPhar) {
            $this->markTestSkipped('Not running as PHAR — requires HATFIELD_BINARY_PATH pointing to built hatfield.phar');
        }

        // Run PHAR from an isolated temp dir to trigger fresh cache
        // creation.  The cache dir suffix must be 12 hex chars (SHA-256)
        // rather than 8 (legacy md5(__FILE__)).
        $tmpCwd = TestDirectoryIsolation::createProjectTempDir('phar-cache-hash');

        $isolatedHome = $this->createIsolatedHome();
        try {
            $process = Process::fromShellCommandline(
                \sprintf(
                    'HOME=%s APP_ENV=prod HATFIELD_CACHE_DIR= %s',
                    escapeshellarg($isolatedHome),
                    $this->shellCommand($cmd, 'list'),
                ),
                cwd: $tmpCwd,
            );
            $process->mustRun();

            // Cache should have been created with a content-hash suffix.
            $cacheDirs = glob($tmpCwd.'/.hatfield/cache/prod-*', \GLOB_ONLYDIR);
            $this->assertNotEmpty(
                $cacheDirs,
                'PHAR did not create a cache directory in the isolated CWD',
            );

            $suffix = substr($cacheDirs[0], strrpos($cacheDirs[0], '-') + 1);

            // Content hash (SHA-256) → 12 hex chars.
            // Legacy md5(__FILE__) → 8 hex chars.  A suffix shorter than
            // 12 indicates the old stable-fixpoint bug has regressed.
            $this->assertSame(
                12,
                \strlen($suffix),
                \sprintf(
                    'Cache dir suffix "%s" should be 12 hex chars (SHA-256 content hash), got %d chars. '
                    .'An 8-char suffix indicates the legacy md5(__FILE__) stable-fixpoint regression.',
                    $suffix,
                    \strlen($suffix),
                ),
            );
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{12}$/',
                $suffix,
                \sprintf('Cache dir suffix "%s" should be lowercase hex', $suffix),
            );
        } finally {
            // Clean up the isolated temp dir even on assertion failure.
            TestDirectoryIsolation::removeDirectory($tmpCwd);
        }
    }

    public function testPharContainsMaterializedInternalDocs(): void
    {
        [$cmd, $pharPath] = $this->resolveArtifactCommand();
        $isPhar = str_ends_with($pharPath, '.phar');

        if (!$isPhar) {
            $this->markTestSkipped(\sprintf(
                'HATFIELD_BINARY_PATH not set or not a PHAR. Resolved to %s.',
                $pharPath,
            ));
        }

        $this->assertFileExists($pharPath);
        $phar = new \Phar($pharPath);
        $expected = [
            'agents',
            'background-processes',
            'compaction',
            'hitl-and-approvals',
            'mcp',
            'prompt-templates',
            'session-storage',
            'settings',
        ];

        foreach ($expected as $id) {
            $entry = 'internal-docs/'.$id.'.md';
            $this->assertTrue(isset($phar[$entry]), 'Missing PHAR entry '.$entry);
            $this->assertFalse($phar[$entry]->isLink(), $entry.' must be a regular file, not a symlink');
            $uri = 'phar://'.$pharPath.'/'.$entry;
            $raw = file_get_contents($uri);
            $this->assertNotFalse($raw, 'Unable to read '.$uri);
            $this->assertStringContainsString('description:', $raw);
            $this->assertStringContainsString('# ', $raw);
        }

        $this->assertFalse(isset($phar['docs/settings.md']), 'PHAR must not bundle docs/');
        $this->assertFalse(isset($phar['docs/archive']), 'PHAR must not bundle docs/archive');

        $locator = new \Ineersa\CodingAgent\Config\AppResourceLocator('phar://'.$pharPath);
        $settingsPath = $locator->getInternalDocsPath().'/settings.md';
        $this->assertFileExists($settingsPath);
        $this->assertStringContainsString('Hatfield Settings', (string) file_get_contents($settingsPath));
    }

    public function testPharBundledResourcesAndProjectLocalSettingsExtension(): void
    {
        [$cmd, $pharPath] = $this->resolveArtifactCommand();
        $isPhar = str_ends_with($pharPath, '.phar');
        if (!$isPhar) {
            $this->markTestSkipped('Not running as PHAR — requires HATFIELD_BINARY_PATH pointing to built hatfield.phar');
        }

        $this->assertFileExists($pharPath);
        $phar = new \Phar($pharPath);

        // Bundled defaults / themes / migrations / internal docs (materialized).
        $requiredEntries = [
            'config/hatfield.defaults.yaml',
            'config/themes/catppuccin-mocha.yaml',
            'migrations/Version20260601152619.php',
            'internal-docs/settings.md',
            'internal-docs/agents.md',
        ];
        foreach ($requiredEntries as $entry) {
            $this->assertTrue(isset($phar[$entry]), 'Missing PHAR entry '.$entry);
            $this->assertFalse($phar[$entry]->isLink(), $entry.' must be a regular file, not a symlink');
        }

        $tmp = TestDirectoryIsolation::createProjectTempDir('phar-project-local');
        try {
            TestDirectoryIsolation::createHatfieldTree($tmp, withSessions: true);
            TestDirectoryIsolation::ensureDirectory($tmp.'/home/.hatfield');
            // Empty HOME settings so project-local settings win for discovery proof.
            file_put_contents($tmp.'/home/.hatfield/settings.yaml', "ai:\n    default_model: null\n");

            // Project-local settings mark a distinctive theme + enable a tiny extension class.
            $extDir = $tmp.'/.hatfield/extensions/proof-ext';
            TestDirectoryIsolation::ensureDirectory($extDir.'/src');
            file_put_contents(
                $extDir.'/src/ProofExtension.php',
                <<<'PHP'
<?php
declare(strict_types=1);
namespace HatfieldProofExt;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
final class ProofExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
        // no-op — presence is enough for load proof
    }
}
PHP
            );
            // Minimal composer autoload for the project-local extension package.
            file_put_contents(
                $tmp.'/.hatfield/extensions/composer.json',
                json_encode([
                    'autoload' => [
                        'psr-4' => [
                            'HatfieldProofExt\\' => 'proof-ext/src/',
                        ],
                    ],
                ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n",
            );
            // Generate a tiny autoloader without requiring network composer.
            $autoload = <<<'PHP'
<?php
spl_autoload_register(static function (string $class): void {
    $prefix = 'HatfieldProofExt\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = __DIR__.'/proof-ext/src/'.$rel.'.php';
    if (is_file($path)) {
        require $path;
    }
});
PHP;
            TestDirectoryIsolation::ensureDirectory($tmp.'/.hatfield/extensions/vendor');
            file_put_contents($tmp.'/.hatfield/extensions/vendor/autoload.php', $autoload);

            file_put_contents(
                $tmp.'/.hatfield/settings.yaml',
                "tui:\n    theme: catppuccin-mocha\nextensions:\n    enabled:\n        - HatfieldProofExt\\ProofExtension\n",
            );

            $home = $tmp.'/home';
            $env = 'HOME='.escapeshellarg($home).' APP_ENV=prod HATFIELD_CACHE_DIR= ';
            $process = Process::fromShellCommandline(
                $env.$this->shellCommand($cmd, 'about 2>&1'),
                cwd: $tmp,
            );
            $process->mustRun();
            $about = $process->getOutput().$process->getErrorOutput();
            $this->assertStringContainsString('Environment', $about);

            // Writable state must stay under isolated CWD, not the PHAR path / home only.
            $this->assertDirectoryExists($tmp.'/.hatfield/cache', 'PHAR must create writable cache under project CWD');

            // Boot list from empty temp project (no source tree).
            $list = Process::fromShellCommandline(
                $env.$this->shellCommand($cmd, 'list 2>&1'),
                cwd: $tmp,
            );
            $list->mustRun();
            $this->assertStringContainsString('agent', $list->getOutput());

            // Extension class must be autoloadable from project-local extensions path.
            $this->assertFileExists($tmp.'/.hatfield/extensions/vendor/autoload.php');
            $this->assertFileExists($tmp.'/.hatfield/extensions/proof-ext/src/ProofExtension.php');
        } finally {
            TestDirectoryIsolation::removeDirectory($tmp);
        }
    }

    /**
     * @return array{0: list<string>, 1: string} command prefix pieces and phar/binary path
     */
    private function resolveArtifactCommand(): array
    {
        $cmd = AgentTestExecutable::command();
        $path = AgentTestExecutable::path();

        return [$cmd, $path];
    }

    private function shellCommand(array $cmd, string $args): string
    {
        $parts = array_map(static fn (string $p): string => escapeshellarg($p), $cmd);

        return implode(' ', $parts).' '.$args;
    }

    /**
     * Create an isolated HOME directory with no user config.
     *
     * The empty HOME dir prevents the PHAR subprocess from inheriting
     * the real user's ~/.hatfield/settings.yaml, which may reference an
     * ai.default_model whose provider definition is not available in the
     * packaged production PHAR (e.g. llama_cpp_test/test defined in a
     * project-level .hatfield/settings.yaml but not in the PHAR provider
     * list).  With an empty HOME, built-in defaults apply and the PHAR
     * picks the first available model from packaged providers.
     */
    private function createIsolatedHome(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('phar-smoke-home');
        $this->isolatedHomeDirs[] = $dir;

        return $dir;
    }

    /**
     * Run a shell command with an isolated HOME.
     */
    private function shellExecIsolated(string $command): string
    {
        $home = $this->createIsolatedHome();

        return shell_exec(
            \sprintf('HOME=%s %s', escapeshellarg($home), $command),
        ) ?? '';
    }
}
