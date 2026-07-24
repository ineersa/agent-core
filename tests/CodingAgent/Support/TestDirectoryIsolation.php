<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

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
        $dir = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(8));
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
     * Normalizes file permissions to ensure writability before unlinking,
     * matching the robustness pattern used in E2E test cleanup.
     *
     * @param string $dir path to the directory to remove
     */
    public static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                @chmod($file->getPathname(), 0644);
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
