<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Docs;

use Ineersa\CodingAgent\Docs\BuiltinDocsCatalog;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: PHAR fingerprint identity includes selected docs and resolved build
 * identity; Extension API docs subtree is excluded from recursive package inputs
 * while selected docs remain explicit; production materialize/strip helpers drop
 * vendor path-package docs and retain non-doc package files.
 *
 * All mutations use isolated temp trees — never the real checkout sources.
 */
final class PharDocsStagingContractTest extends TestCase
{
    public function testSelectedDocContentChangeInvalidatesPharFingerprint(): void
    {
        $helpers = \dirname(__DIR__, 3).'/.castor/helpers.php';
        require_once $helpers;
        $this->assertTrue(\function_exists('CastorTasks\phar_input_fingerprint'));

        $tree = $this->scaffoldFingerprintTree();
        try {
            $before = \CastorTasks\phar_input_fingerprint($tree);
            $settings = $tree.'/docs/settings.md';
            file_put_contents($settings, (string) file_get_contents($settings)."\n<!-- fingerprint-probe -->\n");
            clearstatcache(true, $settings);
            $after = \CastorTasks\phar_input_fingerprint($tree);
            $this->assertNotSame($before, $after, 'changing a selected built-in doc must change PHAR fingerprint');
        } finally {
            TestDirectoryIsolation::removeDirectory($tree);
        }
    }

    public function testResolvedBuildIdentityIsFingerprintedNotRawEnvOnly(): void
    {
        $helpers = \dirname(__DIR__, 3).'/.castor/helpers.php';
        require_once $helpers;

        $tree = $this->scaffoldFingerprintTree();
        try {
            // Initialize a git repo so HEAD resolution is deterministic and local.
            $this->runGit($tree, 'init');
            $this->runGit($tree, 'config user.email test@example.com');
            $this->runGit($tree, 'config user.name test');
            $this->runGit($tree, 'add .');
            $this->runGit($tree, 'commit -m init --quiet');
            $head1 = trim((string) shell_exec('git -C '.escapeshellarg($tree).' rev-parse HEAD'));
            $this->assertNotSame('', $head1);

            putenv('HATFIELD_BUILD_COMMIT');
            putenv('HATFIELD_BUILD_VERSION');
            unset($_ENV['HATFIELD_BUILD_COMMIT'], $_SERVER['HATFIELD_BUILD_COMMIT'], $_ENV['HATFIELD_BUILD_VERSION'], $_SERVER['HATFIELD_BUILD_VERSION']);

            $fp1 = \CastorTasks\phar_input_fingerprint($tree);

            putenv('HATFIELD_BUILD_COMMIT=deadbeefdeadbeefdeadbeefdeadbeefdeadbeef');
            $_ENV['HATFIELD_BUILD_COMMIT'] = $_SERVER['HATFIELD_BUILD_COMMIT'] = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
            $fpEnv = \CastorTasks\phar_input_fingerprint($tree);
            $this->assertNotSame($fp1, $fpEnv, 'explicit HATFIELD_BUILD_COMMIT must change fingerprint');

            putenv('HATFIELD_BUILD_COMMIT');
            unset($_ENV['HATFIELD_BUILD_COMMIT'], $_SERVER['HATFIELD_BUILD_COMMIT']);
            clearstatcache(true);
            $fpRestored = \CastorTasks\phar_input_fingerprint($tree);
            $this->assertSame($fp1, $fpRestored, 'clearing env must restore git-HEAD identity fingerprint');
        } finally {
            putenv('HATFIELD_BUILD_COMMIT');
            putenv('HATFIELD_BUILD_VERSION');
            unset($_ENV['HATFIELD_BUILD_COMMIT'], $_SERVER['HATFIELD_BUILD_COMMIT'], $_ENV['HATFIELD_BUILD_VERSION'], $_SERVER['HATFIELD_BUILD_VERSION']);
            TestDirectoryIsolation::removeDirectory($tree);
        }
    }

    public function testUnmarkedExtensionApiDocsAreExcludedFromPackageInputs(): void
    {
        $helpers = \dirname(__DIR__, 3).'/.castor/helpers.php';
        require_once $helpers;
        $this->assertTrue(\function_exists('CastorTasks\phar_packaged_inputs'));

        $tree = $this->scaffoldFingerprintTree();
        try {
            $unmarked = $tree.'/.hatfield/extensions/extension-api/docs/private-unmarked.md';
            file_put_contents($unmarked, "# Private\n\nnot selected\n");
            $selected = $tree.'/.hatfield/extensions/extension-api/docs/extension-api.md';
            $this->assertFileExists($selected);

            $inputs = \CastorTasks\phar_packaged_inputs($tree);
            $files = $inputs['files'];
            $this->assertContains($selected, $files, 'selected API doc must be an explicit packaged input');
            $this->assertNotContains($unmarked, $files, 'unmarked API doc must not be a packaged input');

            // Recursive directory list must not include the package docs/ tree as a whole directory.
            foreach ($inputs['directories'] as $dir) {
                $this->assertStringNotContainsString('/extension-api/docs', $dir);
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($tree);
        }
    }

    public function testProductionMaterializeStripRemovesVendorDocsAndKeepsPackageFile(): void
    {
        $helpers = \dirname(__DIR__, 3).'/.castor/helpers.php';
        require_once $helpers;
        $this->assertTrue(\function_exists('CastorTasks\materialize_vendor_path_package_symlinks'));
        $this->assertTrue(\function_exists('CastorTasks\strip_vendor_extension_api_docs'));

        if (!\function_exists('symlink')) {
            $this->markTestSkipped('symlink() unavailable on this platform');
        }

        $staging = TestDirectoryIsolation::createProjectTempDir('phar-materialize-staging');
        $packageSrc = TestDirectoryIsolation::createProjectTempDir('phar-extension-api-src');
        try {
            // Path-package source (outside staging) with docs + a non-doc file.
            TestDirectoryIsolation::ensureDirectory($packageSrc.'/docs');
            file_put_contents(
                $packageSrc.'/docs/extension-api.md',
                "---\nbuiltin: true\ndescription: API\n---\n\n# Extension API\n\nbody\n",
            );
            file_put_contents($packageSrc.'/composer.json', "{}\n");
            file_put_contents($packageSrc.'/KEEP.txt', "retain-me\n");

            $vendorLink = $staging.'/vendor/ineersa/hatfield-extension-api';
            TestDirectoryIsolation::ensureDirectory(\dirname($vendorLink));
            if (!@symlink($packageSrc, $vendorLink)) {
                $this->markTestSkipped('Unable to create path-package symlink in test environment');
            }

            // Selected canonical doc staged separately (production stages catalog paths).
            $canonical = $staging.'/.hatfield/extensions/extension-api/docs/extension-api.md';
            TestDirectoryIsolation::ensureDirectory(\dirname($canonical));
            $canonicalBytes = "---\nbuiltin: true\ndescription: API\n---\n\n# Extension API\n\ncanonical-bytes\n";
            file_put_contents($canonical, $canonicalBytes);

            \CastorTasks\materialize_vendor_path_package_symlinks($staging);

            $this->assertFalse(is_link($vendorLink), 'vendor path-package must be materialized to a real directory');
            $this->assertDirectoryDoesNotExist($vendorLink.'/docs');
            $this->assertFileDoesNotExist($vendorLink.'/docs/extension-api.md');
            $this->assertFileExists($vendorLink.'/KEEP.txt');
            $this->assertSame("retain-me\n", (string) file_get_contents($vendorLink.'/KEEP.txt'));
            $this->assertFileExists($vendorLink.'/composer.json');
            $this->assertSame($canonicalBytes, (string) file_get_contents($canonical));
        } finally {
            TestDirectoryIsolation::removeDirectory($staging);
            TestDirectoryIsolation::removeDirectory($packageSrc);
        }
    }

    public function testCatalogRelativePathsAreOnlyApprovedRoots(): void
    {
        $root = \dirname(__DIR__, 3);
        $entries = (new BuiltinDocsCatalog())->discover($root);
        $this->assertNotSame([], $entries);
        foreach ($entries as $entry) {
            $this->assertTrue(
                str_starts_with($entry['relativePath'], 'docs/')
                || str_starts_with($entry['relativePath'], '.hatfield/extensions/extension-api/docs/'),
                $entry['relativePath'],
            );
            $this->assertStringEndsWith('.md', $entry['relativePath']);
            // Selected path must resolve under its declared root (separator-boundary).
            $rootPath = $root.'/'.$entry['rootRelative'];
            $rootReal = realpath($rootPath);
            $pathReal = realpath($entry['absolutePath']);
            $this->assertNotFalse($rootReal);
            $this->assertNotFalse($pathReal);
            $this->assertTrue(
                BuiltinDocsCatalog::pathIsUnderRoot($pathReal, $rootReal),
                $entry['absolutePath'].' not under '.$rootReal,
            );
        }
    }

    private function scaffoldFingerprintTree(): string
    {
        $tree = TestDirectoryIsolation::createProjectTempDir('phar-fingerprint-tree');
        foreach (['bin', 'src', 'config', 'migrations', '.castor', 'tools/phar', 'docs', '.hatfield/extensions/extension-api/docs'] as $dir) {
            TestDirectoryIsolation::ensureDirectory($tree.'/'.$dir);
        }
        file_put_contents($tree.'/composer.json', "{}\n");
        file_put_contents($tree.'/composer.lock', "{}\n");
        file_put_contents($tree.'/box.json', "{}\n");
        file_put_contents($tree.'/castor.php', "<?php\n");
        file_put_contents($tree.'/tools/phar/composer.json', "{}\n");
        file_put_contents($tree.'/tools/phar/composer.lock', "{}\n");
        file_put_contents($tree.'/.hatfield/extensions/extension-api/composer.json', "{}\n");
        file_put_contents(
            $tree.'/docs/settings.md',
            "---\nbuiltin: true\ndescription: Settings\n---\n\n# Settings\n\nbody\n",
        );
        file_put_contents(
            $tree.'/.hatfield/extensions/extension-api/docs/extension-api.md',
            "---\nbuiltin: true\ndescription: Extension API\n---\n\n# Extension API\n\nbody\n",
        );

        return $tree;
    }

    private function runGit(string $tree, string $args): void
    {
        $cmd = 'git -C '.escapeshellarg($tree).' '.$args.' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, $cmd."\n".implode("\n", $out));
    }
}
