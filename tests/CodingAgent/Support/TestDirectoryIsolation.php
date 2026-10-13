<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use CastorTasks\TestArtifactLifecycle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

require_once \dirname(__DIR__, 3).'/.castor/TestArtifactLifecycle.php';

/**
 * Shared temporary-directory creation and cleanup for tests.
 *
 * Provides static methods to create temporary directories under project
 * var/tmp/ or OS temp, scaffold .hatfield trees, and recursively remove
 * trees with permission normalization for robust cleanup.
 *
 * All methods are static and safe to use from setUp/tearDown, try/finally
 * blocks, or static lifecycle callbacks.
 */
final class TestDirectoryIsolation
{
    /** @var array<string, string> */
    private static array $diagnosticReports = [];

    /** Archive explicitly requested test proof without keeping the working tree. */
    public static function preserveDirectory(string $source, ?string $projectRoot = null, ?TestArtifactLifecycle $lifecycle = null): string
    {
        $root = $projectRoot ?? ProjectDir::get();
        if (!str_starts_with($source, $root.'/var/tmp/') || realpath($source) !== $source || !is_dir($source)) {
            throw new \InvalidArgumentException('Invalid isolated test diagnostic source.');
        }
        $configured = getenv('HATFIELD_QA_REPORTS_DIR');
        $report = false === $configured ? '' : $configured;
        if ('' !== $report && !str_starts_with($report, '/')) {
            $report = $root.'/'.$report;
        }
        $marker = $report.'/.artifact-owner.json';
        $owner = is_file($marker) && !is_link($marker) ? json_decode((string) file_get_contents($marker), true) : null;
        $active = false;
        if (\is_array($owner) && ($owner['root'] ?? null) === $root && \is_int($owner['pid'] ?? null)
            && \is_string($owner['id'] ?? null) && 1 === preg_match('/^[a-f0-9]{32}$/D', $owner['id'])
            && \is_array($owner['paths'] ?? null) && array_is_list($owner['paths'])
            && \is_string($owner['start'] ?? null) && null === ($owner['completed'] ?? null)
            && 1 === preg_match('~^'.preg_quote($root, '~').'/var/reports/qa-[A-Za-z0-9._-]+$~D', $report)
            && realpath($report) === $report) {
            $stat = @file_get_contents('/proc/'.$owner['pid'].'/stat');
            if (false !== $stat && false !== ($end = strrpos($stat, ')'))) {
                $fields = explode(' ', substr($stat, $end + 2));
                $active = ($fields[19] ?? null) === $owner['start'] && !\in_array($fields[0], ['Z', 'X'], true);
            }
        }
        if (!$active) {
            if (!isset(self::$diagnosticReports[$root])) {
                $report = $root.'/var/reports/qa-test-'.date('Ymd-His').'-'.getmypid().'-'.bin2hex(random_bytes(8));
                ($lifecycle ?? TestArtifactLifecycle::current($root))->report($report);
                self::$diagnosticReports[$root] = $report;
            }
            $report = self::$diagnosticReports[$root];
        }
        $destination = $report.'/diagnostics/'.basename($source).'-'.substr(hash('sha256', $source), 0, 12);
        // FIFOs and sockets are live coordination resources, not persisted proof.
        // Mirroring them is unsupported and reading a FIFO can block forever.
        $entries = (new Finder())->in($source)->ignoreDotFiles(false)->ignoreVCS(false)
            ->filter(static fn (\SplFileInfo $entry): bool => $entry->isFile() || $entry->isDir() || $entry->isLink());
        (new Filesystem())->mirror($source, $destination, iterator: $entries, options: ['override' => true]);

        return $destination;
    }

    /**
     * Create a temporary directory under the project's var/tmp/.
     *
     * Returns the full path, created with the given permissions.
     *
     * @param string $prefix      directory name prefix
     * @param int    $permissions directory permissions (octal)
     */
    public static function createProjectTempDir(string $prefix = 'test', int $permissions = 0o750): string
    {
        $projectDir = ProjectDir::get();
        $dir = $projectDir.'/var/tmp/'.$prefix.'-'.bin2hex(random_bytes(8));
        TestArtifactLifecycle::current($projectDir)->own($dir);
        mkdir($dir, $permissions, true);

        return $dir;
    }

    /**
     * Create a temporary directory under the OS temp directory.
     *
     * Returns the full path, created with the given permissions.
     *
     * @param string $prefix      directory name prefix
     * @param int    $permissions directory permissions (octal)
     */
    public static function createOsTempDir(string $prefix = 'test', int $permissions = 0o750): string
    {
        // macOS system temp paths commonly traverse /var -> /private/var.
        // Register the canonical allocation rather than a symlink escape.
        $tempRoot = realpath(sys_get_temp_dir());
        if (false === $tempRoot) {
            throw new \RuntimeException('System temporary directory does not exist.');
        }
        $dir = $tempRoot.'/'.$prefix.'-'.bin2hex(random_bytes(8));
        TestArtifactLifecycle::current(ProjectDir::get())->own($dir);
        mkdir($dir, $permissions, true);

        return $dir;
    }

    /**
     * Ensure a directory exists, creating it recursively if needed.
     *
     * @throws \RuntimeException if a non-directory filesystem entry exists at the path
     */
    public static function ensureDirectory(string $dir, int $permissions = 0o755): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (file_exists($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create directory: a non-directory filesystem entry exists at "%s".', $dir));
        }

        mkdir($dir, $permissions, true);
    }

    /**
     * Scaffold a .hatfield project tree at the given root.
     *
     * Creates:
     *   <root>/.hatfield/
     *   <root>/.hatfield/.gitignore
     *   <root>/.hatfield/settings.yaml (minimal)
     *   <root>/.hatfield/sessions/   (optional, when $withSessions=true)
     *
     * @param string $root         Root directory under which .hatfield/ is created.
     * @param bool   $withSessions whether to create the sessions subdirectory
     * @param int    $permissions  permissions for directories
     */
    public static function createHatfieldTree(string $root, bool $withSessions = false, int $permissions = 0o755): void
    {
        $hatfieldDir = $root.'/.hatfield';
        self::ensureDirectory($hatfieldDir, $permissions);

        if ($withSessions) {
            self::ensureDirectory($hatfieldDir.'/sessions', $permissions);
        }

        if (!is_file($hatfieldDir.'/.gitignore')) {
            file_put_contents($hatfieldDir.'/.gitignore', "*\n");
        }

        if (!is_file($hatfieldDir.'/settings.yaml')) {
            // Provide a minimal enabled model catalog so child-launch model pinning
            // and schedule-time resolution can fail closed only on real missing
            // identity, not on empty isolation settings. Override home settings
            // that may reference unavailable providers.
            file_put_contents(
                $hatfieldDir.'/settings.yaml',
                <<<'YAML'
# hatfield settings (test isolation)
ai:
    default_model: llama_cpp_test/test
    providers:
        llama_cpp_test:
            type: generic
            enabled: true
            base_url: 'http://127.0.0.1:9052/v1'
            api: openai-completions
            api_key: dummy
            completions_path: /chat/completions
            supports_completions: true
            models:
                test:
                    id: test
                    name: test
                    context_window: 8192
                    max_tokens: 1024
                    input: [text]
                    tool_calling: true
YAML
            );
        }
    }

    /**
     * Recursively remove a directory tree.
     *
     * Normalizes directory permissions before removal without following symlinks.
     *
     * @param string $dir path to the directory to remove
     */
    public static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir) && !is_link($dir)) {
            TestArtifactLifecycle::current(ProjectDir::get())->forgetRemovedPath($dir);

            return;
        }

        // First pass clears the known tree. A concurrent writer (for example a TUI agent
        // flushing ~/.hatfield/ai-catalog.yaml during tearDown) can recreate entries between
        // the child walk and the final rmdir. Ownership teardown must stop writers first;
        // a second immediate pass only absorbs the unlink/rmdir TOCTOU window and must not
        // replace process ownership. Framework removal is a no-op when already gone.
        TestArtifactLifecycle::removePath($dir);
        TestArtifactLifecycle::removePath($dir);
        TestArtifactLifecycle::current(ProjectDir::get())->forgetRemovedPath($dir);
    }
}
