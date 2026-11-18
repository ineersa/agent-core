<?php

declare(strict_types=1);

/*
 * Shared bootstrap for the Claude Code entry points in claude/bin.
 *
 * The plugin directory is a read-only, versioned copy without vendor/. On first
 * start (and after every plugin update) this installs the package's Composer
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
    require observationalMemoryDependencies($pluginRoot, $dataDir);

    return ['pluginRoot' => $pluginRoot, 'dataDir' => $dataDir, 'projectDir' => $projectDir];
}

function observationalMemoryDependencies(string $pluginRoot, string $dataDir): string
{
    $autoload = $dataDir.'/vendor/autoload.php';
    // The stamp covers this generated file, which embeds the plugin root path
    // (it changes on every update), and the package's own requirements.
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
    $stamp = hash('sha256', $composerJsonText.file_get_contents($pluginRoot.'/composer.json'));
    $stampFile = $dataDir.'/install.stamp';
    if (is_file($autoload) && @file_get_contents($stampFile) === $stamp) {
        return $autoload;
    }

    if (!is_dir($dataDir) && !mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
        observationalMemoryFail('cannot create plugin data directory');
    }
    // Concurrent Claude sessions, the MCP server and hooks share the data directory; install once.
    $lock = fopen($dataDir.'/install.lock', 'cb');
    if (false === $lock || !flock($lock, \LOCK_EX)) {
        observationalMemoryFail('cannot lock plugin data directory');
    }
    if (is_file($autoload) && @file_get_contents($stampFile) === $stamp) {
        return $autoload;
    }

    // A failed update must not leave a stamp that vouches for a mixed vendor/.
    @unlink($stampFile);
    file_put_contents($dataDir.'/composer.json', $composerJsonText);

    $composer = getenv('COMPOSER_BINARY');
    if (!is_string($composer) || '' === $composer) {
        $composer = 'composer';
    }
    fwrite(\STDERR, "observational-memory: installing Composer dependencies into {$dataDir}\n");
    $process = proc_open(
        [$composer, 'update', '--no-dev', '--no-interaction', '--no-progress', '--optimize-autoloader', '--working-dir='.$dataDir],
        [0 => ['file', '/dev/null', 'r'], 1 => \STDERR, 2 => \STDERR],
        $pipes,
    );
    if (!is_resource($process) || 0 !== proc_close($process) || !is_file($autoload)) {
        observationalMemoryFail('composer update failed; run it manually in '.$dataDir);
    }
    file_put_contents($stampFile, $stamp);
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
