<?php

declare(strict_types=1);
use Symfony\Component\Filesystem\Filesystem;

/*
 * Shared bootstrap for the Claude Code entry points in claude/bin.
 *
 * The plugin directory is a read-only, versioned copy without vendor/. On first
 * start of each plugin version this installs the package's Composer
 * dependencies into the persistent plugin data directory. stdout is reserved
 * for the MCP protocol (and for hooks would reach the conversation): Composer
 * output and diagnostics go to stderr.
 */

/** @return array{pluginRoot: string, dataDir: string, projectDir: string} */
function observationalMemoryBootstrap(): array
{
    $pluginRoot = dirname(__DIR__);
    // Claude Code exports these to plugin MCP servers and hooks. Refuse anything
    // else (unset, relative, an unexpanded placeholder) so nothing is installed
    // into, or resolved against, the current directory by accident.
    $dataDir = observationalMemoryAbsoluteEnv('CLAUDE_PLUGIN_DATA');
    $projectDir = realpath(observationalMemoryAbsoluteEnv('CLAUDE_PROJECT_DIR'));
    if (false === $projectDir || !is_dir($projectDir)) {
        observationalMemoryFail('CLAUDE_PROJECT_DIR is not a directory');
    }
    require_once observationalMemoryDependencies($pluginRoot, $dataDir);

    return ['pluginRoot' => $pluginRoot, 'dataDir' => $dataDir, 'projectDir' => $projectDir];
}

/**
 * Each plugin version gets its own installation, keyed by a hash of its
 * generated composer.json (which embeds the version-specific plugin root) and
 * the package's requirements. After an update, running sessions keep the old
 * plugin root: separate installations keep their hooks and MCP servers from
 * re-installing over each other's vendor/.
 */
function observationalMemoryDependencies(string $pluginRoot, string $dataDir): string
{
    $composerJson = [
        'name' => 'local/observational-memory-claude-plugin',
        'type' => 'project',
        'require' => [
            'ineersa/hatfield-ext-observational-memory' => '*@dev',
            // Track the Extension API mirror's main, which is split from the same
            // monorepo commit as this plugin, not its latest (possibly older) tag.
            'ineersa/hatfield-extension-api' => 'dev-main',
        ],
        'repositories' => [['type' => 'path', 'url' => $pluginRoot, 'options' => ['symlink' => true]]],
        'minimum-stability' => 'dev',
        'prefer-stable' => true,
    ];
    $composerJsonText = json_encode($composerJson, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n";
    $installs = $dataDir.'/install';
    $installDir = $installs.'/'.hash('sha256', $composerJsonText.file_get_contents($pluginRoot.'/composer.json'));
    $autoload = $installDir.'/vendor/autoload.php';
    // Written last: a failed or interrupted install never looks complete.
    $complete = $installDir.'/install.complete';
    if (is_file($complete)) {
        return $autoload;
    }

    if (!is_dir($installs) && !mkdir($installs, 0700, true) && !is_dir($installs)) {
        observationalMemoryFail('cannot create plugin data directory');
    }
    // Concurrent Claude sessions, the MCP server and hooks share the data directory; install once.
    $lock = fopen($dataDir.'/install.lock', 'cb');
    if (false === $lock || !flock($lock, \LOCK_EX)) {
        observationalMemoryFail('cannot lock plugin data directory');
    }
    if (is_file($complete)) {
        return $autoload;
    }

    if (!is_dir($installDir) && !mkdir($installDir, 0700) && !is_dir($installDir)) {
        observationalMemoryFail('cannot create the installation directory');
    }
    file_put_contents($installDir.'/composer.json', $composerJsonText);

    $composer = getenv('COMPOSER_BINARY');
    if (!is_string($composer) || '' === $composer) {
        $composer = 'composer';
    }
    fwrite(\STDERR, "observational-memory: installing Composer dependencies into {$installDir}\n");
    $process = proc_open(
        [$composer, 'update', '--no-dev', '--no-interaction', '--no-progress', '--optimize-autoloader', '--working-dir='.$installDir],
        [0 => ['file', '/dev/null', 'r'], 1 => \STDERR, 2 => \STDERR],
        $pipes,
    );
    if (!is_resource($process) || 0 !== proc_close($process) || !is_file($autoload)) {
        observationalMemoryFail('composer update failed; run it manually in '.$installDir);
    }
    file_put_contents($complete, '');

    require_once $autoload;
    // Installations of plugin versions Claude Code has removed are unusable:
    // their vendor/ symlinks the package into the missing plugin root.
    $installed = glob($installs.'/*/composer.json');
    foreach (false === $installed ? [] : $installed as $file) {
        $url = json_decode((string) file_get_contents($file), true)['repositories'][0]['url'] ?? null;
        if (is_string($url) && !is_dir($url)) {
            (new Filesystem())->remove(dirname($file));
        }
    }
    flock($lock, \LOCK_UN);

    return $autoload;
}

function observationalMemoryAbsoluteEnv(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || !str_starts_with($value, '/') || str_contains($value, '${')) {
        observationalMemoryFail($name.' must be an absolute path; start this through the Claude Code plugin');
    }

    return rtrim($value, '/');
}

function observationalMemoryFail(string $message): never
{
    fwrite(\STDERR, 'observational-memory: '.$message."\n");
    exit(1);
}
