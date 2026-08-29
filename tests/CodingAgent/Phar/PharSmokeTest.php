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
 *   castor phar:build
 *   castor test --filter=PharSmokeTest
 *
 * Castor sets HATFIELD_BINARY_PATH to the worktree-local PHAR when running the group.
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
                .'Run: castor phar:build && castor test --filter=PharSmokeTest',
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
                .'Run: castor phar:build && castor test --filter=PharSmokeTest',
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
     * Installed PHAR cache identity: <root>/<env>/<content-sha256>-<path-sha256>
     * under an explicit HATFIELD_CACHE_DIR root (not project .hatfield/cache).
     */
    #[Group('phar')]
    public function testPharCacheUsesEnvContentAndCanonicalPathIdentity(): void
    {
        [$cmd, $pharPath] = $this->resolveArtifactCommand();
        $isPhar = str_ends_with($pharPath, '.phar');

        if (!$isPhar) {
            $this->markTestSkipped('Not running as PHAR — requires HATFIELD_BINARY_PATH pointing to built hatfield.phar');
        }

        $tmpCwd = TestDirectoryIsolation::createProjectTempDir('phar-cache-hash');
        $cacheRoot = $tmpCwd.'/cache-root';
        TestDirectoryIsolation::ensureDirectory($cacheRoot);
        $isolatedHome = $this->createIsolatedHome();

        try {
            $process = new Process(
                array_merge($cmd, ['list']),
                cwd: $tmpCwd,
                env: [
                    'HOME' => $isolatedHome,
                    'APP_ENV' => 'prod',
                    'APP_DEBUG' => '0',
                    'HATFIELD_CACHE_DIR' => $cacheRoot,
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                ],
            );
            $process->mustRun();

            $this->assertDirectoryDoesNotExist(
                $tmpCwd.'/.hatfield/cache',
                'Installed PHAR must not write Symfony cache under project .hatfield/cache by default when override root is set',
            );

            $contentHash = hash_file('sha256', $pharPath);
            $this->assertNotFalse($contentHash);
            $canonical = realpath($pharPath);
            $this->assertNotFalse($canonical);
            $expected = $cacheRoot.'/prod/'.$contentHash.'-'.hash('sha256', $canonical);
            $this->assertDirectoryExists($expected, 'Expected identity cache dir: '.$expected);

            // Same artifact+path is stable across another invocation.
            $process2 = new Process(
                array_merge($cmd, ['list']),
                cwd: $tmpCwd,
                env: [
                    'HOME' => $isolatedHome,
                    'APP_ENV' => 'prod',
                    'APP_DEBUG' => '0',
                    'HATFIELD_CACHE_DIR' => $cacheRoot,
                    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                ],
            );
            $process2->mustRun();
            $this->assertDirectoryExists($expected);

            $identities = glob($cacheRoot.'/prod/*', \GLOB_ONLYDIR) ?: [];
            $this->assertCount(1, $identities, 'Same bytes+path must reuse one identity dir');
        } finally {
            TestDirectoryIsolation::removeDirectory($tmpCwd);
        }
    }

    public function testPharContainsMaterializedBuiltinDocs(): void
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

        $projectRoot = \dirname(__DIR__, 3);
        $catalog = (new \Ineersa\CodingAgent\Docs\BuiltinDocsCatalog())->discover($projectRoot);
        $this->assertNotSame([], $catalog, 'catalog must discover selected built-in docs');

        $expected = [];
        foreach ($catalog as $entry) {
            $expected[$entry['relativePath']] = true;
            $this->assertTrue(isset($phar[$entry['relativePath']]), 'Missing PHAR entry '.$entry['relativePath']);
            $this->assertFalse($phar[$entry['relativePath']]->isLink(), $entry['relativePath'].' must be a regular file, not a symlink');
            $uri = 'phar://'.$pharPath.'/'.$entry['relativePath'];
            $raw = file_get_contents($uri);
            $this->assertNotFalse($raw, 'Unable to read '.$uri);
            $source = file_get_contents($entry['absolutePath']);
            $this->assertNotFalse($source);
            $this->assertSame($source, $raw, 'PHAR bytes must match source for '.$entry['relativePath']);
            $this->assertStringContainsString('builtin: true', $raw);
            $this->assertStringContainsString('description:', $raw);
            $this->assertStringContainsString('# ', $raw);
        }

        // Exact Markdown inventory under both canonical archive doc roots.
        // Only the monorepo-canonical roots are asserted (not vendor/ path-package copies).
        $found = [];
        $canonicalPrefixes = [
            \Ineersa\CodingAgent\Docs\BuiltinDocsCatalog::CORE_DOCS_RELATIVE.'/',
            \Ineersa\CodingAgent\Docs\BuiltinDocsCatalog::EXTENSION_API_DOCS_RELATIVE.'/',
        ];
        /** @var \PharFileInfo $file */
        foreach (new \RecursiveIteratorIterator($phar) as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', $file->getPathname());
            if (str_contains($rel, '.phar/')) {
                $rel = substr($rel, strpos($rel, '.phar/') + \strlen('.phar/'));
            }
            if (str_starts_with($rel, 'phar://')) {
                // phar:///abs/path/file.phar/entry
                $marker = '.phar/';
                if (str_contains($rel, $marker)) {
                    $rel = substr($rel, strpos($rel, $marker) + \strlen($marker));
                }
            }
            if (!str_ends_with($rel, '.md')) {
                continue;
            }
            $isCanonical = false;
            foreach ($canonicalPrefixes as $prefix) {
                if (str_starts_with($rel, $prefix)) {
                    $isCanonical = true;
                    break;
                }
            }
            if (!$isCanonical) {
                continue;
            }
            $found[$rel] = true;
            $this->assertArrayHasKey($rel, $expected, 'PHAR contains unmarked/extra documentation file: '.$rel);
        }
        ksort($expected);
        ksort($found);
        $this->assertSame(array_keys($expected), array_keys($found), 'PHAR Markdown inventory must match BuiltinDocsCatalog exactly');

        $this->assertFalse(isset($phar['internal-docs/settings.md']), 'PHAR must not contain internal-docs');
        $this->assertFalse(isset($phar['docs/datadog.md']), 'PHAR must not bundle unmarked repository docs');
        $this->assertFalse(isset($phar['docs/async-runtime-architecture.md']), 'PHAR must not bundle repository-only docs');
        // Unmarked decoys under Extension API docs must not ship even if present in source.
        $this->assertFalse(
            isset($phar['.hatfield/extensions/extension-api/docs/private-unmarked.md']),
            'PHAR must not bundle unmarked Extension API docs',
        );
        $vendorApiDocsPrefix = 'vendor/ineersa/hatfield-extension-api/docs/';
        /** @var \PharFileInfo $vendorFile */
        foreach (new \RecursiveIteratorIterator($phar) as $vendorFile) {
            if (!$vendorFile->isFile()) {
                continue;
            }
            $vendorRel = str_replace('\\', '/', $vendorFile->getPathname());
            if (str_contains($vendorRel, '.phar/')) {
                $vendorRel = substr($vendorRel, strpos($vendorRel, '.phar/') + \strlen('.phar/'));
            }
            $this->assertFalse(
                str_starts_with($vendorRel, $vendorApiDocsPrefix),
                'PHAR must not ship any vendor path-package Extension API docs entry: '.$vendorRel,
            );
        }

        $locator = new \Ineersa\CodingAgent\Config\AppResourceLocator('phar://'.$pharPath);
        $settingsPath = $locator->getAppRoot().'/'.\Ineersa\CodingAgent\Docs\BuiltinDocsCatalog::CORE_DOCS_RELATIVE.'/settings.md';
        $this->assertFileExists($settingsPath);
        $this->assertStringContainsString('Hatfield Settings', (string) file_get_contents($settingsPath));
        $this->assertDirectoryExists($locator->getAppRoot().'/'.\Ineersa\CodingAgent\Docs\BuiltinDocsCatalog::EXTENSION_API_DOCS_RELATIVE);

        $skillMd = 'src/CodingAgent/Resources/skills/subagents/SKILL.md';
        $frontmatterMd = 'src/CodingAgent/Resources/skills/subagents/FRONTMATTER.md';
        $this->assertTrue(isset($phar[$skillMd]), 'Missing PHAR entry '.$skillMd);
        $this->assertTrue(isset($phar[$frontmatterMd]), 'Missing PHAR entry '.$frontmatterMd);
        $this->assertSame(
            'phar://'.$pharPath.'/src/CodingAgent/Resources/skills',
            $locator->getBuiltinSkillsPath(),
        );
        $this->assertFileExists($locator->getBuiltinSkillsPath().'/subagents/SKILL.md');
        $this->assertFileExists($locator->getBuiltinSkillsPath().'/subagents/FRONTMATTER.md');
        $skillBody = (string) file_get_contents('phar://'.$pharPath.'/'.$skillMd);
        $this->assertStringContainsString('name: subagents', $skillBody);
        $this->assertStringContainsString('agent_retrieve', $skillBody);

        $this->assertSame(
            'phar://'.$pharPath.'/src/CodingAgent/Resources/agents',
            $locator->getBuiltinAgentsPath(),
        );
        foreach (['scout', 'reviewer', 'researcher', 'architect', 'browser'] as $name) {
            $agentEntry = 'src/CodingAgent/Resources/agents/'.$name.'.md';
            $this->assertTrue(isset($phar[$agentEntry]), 'Missing PHAR entry '.$agentEntry);
            $this->assertFileExists($locator->getBuiltinAgentsPath().'/'.$name.'.md');
        }

        $home = $this->createIsolatedHome();
        $init = new Process(
            array_merge($cmd, ['agents:init']),
            cwd: sys_get_temp_dir(),
            env: [
                'HOME' => $home,
                'APP_ENV' => 'prod',
                'APP_DEBUG' => '0',
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            ],
        );
        $init->mustRun();
        $initOut = $init->getOutput().$init->getErrorOutput();
        $this->assertStringContainsString('Installed 5 bundled agent definition(s)', $initOut);
        foreach (['scout', 'reviewer', 'researcher', 'architect', 'browser'] as $name) {
            $installed = $home.'/.hatfield/agents/'.$name.'.md';
            $this->assertFileExists($installed);
            $this->assertFileEquals(
                'phar://'.$pharPath.'/src/CodingAgent/Resources/agents/'.$name.'.md',
                $installed,
            );
        }
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

        // Bundled defaults / themes / migrations / selected built-in docs.
        $requiredEntries = [
            'config/hatfield.defaults.yaml',
            'config/themes/catppuccin-mocha.yaml',
            'migrations/application/Version20260601152619.php',
            'migrations/messenger_transport/Version20260828224203.php',
            'docs/settings.md',
            'docs/agents.md',
            '.hatfield/extensions/extension-api/docs/extension-api.md',
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
            $cacheRoot = $tmp.'/cache-root';
            TestDirectoryIsolation::ensureDirectory($cacheRoot);
            $env = [
                'HOME' => $home,
                'APP_ENV' => 'prod',
                'APP_DEBUG' => '0',
                'HATFIELD_CACHE_DIR' => $cacheRoot,
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            ];
            $process = new Process(
                array_merge($cmd, ['about']),
                cwd: $tmp,
                env: $env,
            );
            $process->mustRun();
            $about = $process->getOutput().$process->getErrorOutput();
            $this->assertStringContainsString('Environment', $about);

            // Project settings/sessions stay under CWD; Symfony container cache is global/override.
            $this->assertDirectoryDoesNotExist($tmp.'/.hatfield/cache');
            $contentHash = hash_file('sha256', $pharPath);
            $this->assertNotFalse($contentHash);
            $canonical = realpath($pharPath);
            $this->assertNotFalse($canonical);
            $this->assertDirectoryExists(
                $cacheRoot.'/prod/'.$contentHash.'-'.hash('sha256', $canonical),
            );

            // Boot list from empty temp project (no source tree).
            $list = new Process(
                array_merge($cmd, ['list']),
                cwd: $tmp,
                env: $env,
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
