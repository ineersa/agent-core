<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Replay\ControllerReplayLowLatencyMessengerConsole;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Symfony\Component\Yaml\Yaml;

/**
 * Isolated SQLite paths for TUI E2E subprocesses (app state + Messenger transport).
 *
 * TUI tmux tests spawn agent/controller children that do not inherit ParaTest
 * worker env; each test must pass both HATFIELD_TEST_* paths explicitly so
 * parallel TUI workers do not share one messenger_transport_test.sqlite.
 */
final class TuiE2eDatabaseEnv
{
    /**
     * @return array{app: string, transport: string}
     */
    public static function allocatePaths(string $prefix): array
    {
        $suffix = $prefix.'-'.bin2hex(random_bytes(4));

        return [
            'app' => 'app_test-'.$suffix.'.sqlite',
            'transport' => 'messenger_transport_test-'.$suffix.'.sqlite',
        ];
    }

    public static function shellPrefix(string $appDbPath, string $transportDbPath): string
    {
        return self::shellPrefixWithOptionalMessengerBinary($appDbPath, $transportDbPath, null);
    }

    /**
     * Same as shellPrefix, plus HATFIELD_BINARY_PATH → low-latency messenger
     * console wrapper under the isolated project tree (source-console TUI
     * replay only; do not use for PHAR/artifact boots).
     */
    public static function shellPrefixWithLowLatencyMessenger(
        string $appDbPath,
        string $transportDbPath,
        string $testProjectDir,
    ): string {
        return self::shellPrefixWithOptionalMessengerBinary($appDbPath, $transportDbPath, $testProjectDir);
    }

    /**
     * Derive paired transport filename from an app_test-*.sqlite basename.
     */
    public static function transportPathForAppBasename(string $appBasename): string
    {
        if (str_starts_with($appBasename, 'app_test-')) {
            return 'messenger_transport_test-'.substr($appBasename, \strlen('app_test-'));
        }

        return 'messenger_transport_'.$appBasename;
    }

    /**
     * @return array{app: string, transport: string}
     */
    public static function allocatePathsFromAppBasename(string $appBasename): array
    {
        return [
            'app' => $appBasename,
            'transport' => self::transportPathForAppBasename($appBasename),
        ];
    }

    /**
     * Physical SQLite directory inside an isolated TUI E2E project tree.
     *
     * DB files must live under the per-test temp dir so tearDown removes them via
     * TestDirectoryIsolation::removeDirectory().
     */
    public static function isolatedSqliteDirectory(string $isolatedProjectDir): string
    {
        return rtrim($isolatedProjectDir, '/').'/.hatfield/tmp/test-db';
    }

    /**
     * Absolute path for raw PDO seeding (same file Doctrine opens for the agent).
     */
    public static function isolatedSqliteAbsolutePath(string $isolatedProjectDir, string $basename): string
    {
        return self::isolatedSqliteDirectory($isolatedProjectDir).'/'.$basename;
    }

    /**
     * Value for HATFIELD_TEST_* env vars given test Doctrine config:
     *   %kernel.project_dir%/var/test/{env}
     *
     * The env value is a relative path from {kernel.project_dir}/var/test/ to the
     * isolated absolute SQLite file. Raw PDO must use isolatedSqliteAbsolutePath()
     * instead — opening only the basename under the isolated tree was the GF-04 bug.
     */
    public static function doctrineEnvPathForIsolatedSqlite(
        string $kernelProjectDir,
        string $isolatedProjectDir,
        string $basename,
    ): string {
        $absolute = self::isolatedSqliteAbsolutePath($isolatedProjectDir, $basename);
        $varTestAnchor = rtrim($kernelProjectDir, '/').'/var/test';

        return self::relativePath($varTestAnchor, $absolute);
    }

    /**
     * @return array{app: string, transport: string, appAbsolute: string, transportAbsolute: string, appEnv: string, transportEnv: string}
     */
    public static function allocateIsolatedPaths(string $kernelProjectDir, string $isolatedProjectDir, string $prefix): array
    {
        $paths = self::allocatePaths($prefix);
        @mkdir(self::isolatedSqliteDirectory($isolatedProjectDir), 0o777, true);

        return [
            'app' => $paths['app'],
            'transport' => $paths['transport'],
            'appAbsolute' => self::isolatedSqliteAbsolutePath($isolatedProjectDir, $paths['app']),
            'transportAbsolute' => self::isolatedSqliteAbsolutePath($isolatedProjectDir, $paths['transport']),
            'appEnv' => self::doctrineEnvPathForIsolatedSqlite($kernelProjectDir, $isolatedProjectDir, $paths['app']),
            'transportEnv' => self::doctrineEnvPathForIsolatedSqlite($kernelProjectDir, $isolatedProjectDir, $paths['transport']),
        ];
    }

    public static function shellPrefixForIsolatedEnv(string $appEnvPath, string $transportEnvPath): string
    {
        return \sprintf(
            'HATFIELD_TEST_DATABASE_PATH=%s HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH=%s ',
            escapeshellarg($appEnvPath),
            escapeshellarg($transportEnvPath),
        );
    }

    /**
     * Ensure messenger_messages exists on the isolated transport SQLite file.
     *
     * doctrine:migrations:migrate may not touch the transport connection in all setups;
     * agent startup uses MessengerTransportSchemaEnsurer on the transport DB path.
     */
    public static function ensureIsolatedMessengerTransportSchema(string $transportDbAbsolutePath): void
    {
        $pdo = new \PDO('sqlite:'.$transportDbAbsolutePath);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS messenger_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                body TEXT NOT NULL,
                headers TEXT NOT NULL,
                queue_name TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL
            )',
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS IDX_75EA56E0E3BD61C1 ON messenger_messages (available_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)');
    }

    /**
     * Prefix for tmux agent shell commands: APP_ENV=test plus paired DB env vars.
     */
    public static function agentShellPrefix(string $appDbPath, string $transportDbPath): string
    {
        return 'APP_ENV=test '.self::shellPrefix($appDbPath, $transportDbPath);
    }

    /**
     * Replay fixtures use a process-local FIFO cursor inside each llm worker.
     * With production default runtime.llm_worker_count=4, concurrent workers each
     * restart at fixture 0 and can serve the wrong turn. Force a single llm consumer
     * for all TUI replay isolation (same rationale as ControllerReplayE2eTestCase).
     * Also force tools.execution.max_parallelism=1: TUI replay cases do not need
     * four idle tool consumers paying Symfony messenger --sleep=1 under contention.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public static function withSingleLlmWorkerForReplay(array $settings): array
    {
        $runtime = $settings['runtime'] ?? [];
        if (!\is_array($runtime)) {
            $runtime = [];
        }
        $runtime['llm_worker_count'] = 1;
        $settings['runtime'] = $runtime;

        $tools = $settings['tools'] ?? [];
        if (!\is_array($tools)) {
            $tools = [];
        }
        $execution = $tools['execution'] ?? [];
        if (!\is_array($execution)) {
            $execution = [];
        }
        $execution['max_parallelism'] = 1;
        $tools['execution'] = $execution;
        $settings['tools'] = $tools;

        return $settings;
    }

    /**
     * Canonical replay settings used by the dominant TUI E2E scaffolds.
     *
     * Tests apply shallow per-key overrides / plain array edits on top.
     * Key insertion order matters: Yaml::dump preserves it for byte-stable fixtures.
     *
     * @return array<string, mixed>
     */
    public static function replayBaseSettings(): array
    {
        return [
            'ai' => [
                'default_model' => 'llama_cpp_test/test',
                'default_reasoning' => 'off',
                'providers' => [
                    'llama_cpp_test' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'http://192.168.2.38:9052/v1',
                        'api' => 'openai-completions',
                        'api_key' => 'dummy',
                        'completions_path' => '/chat/completions',
                        'supports_completions' => true,
                        'supports_embeddings' => false,
                        'supports_thinking_levels' => true,
                        'models' => [
                            'test' => [
                                'name' => 'test',
                                'context_window' => 32768,
                                'max_tokens' => 32768,
                                'input' => ['text', 'image'],
                                'tool_calling' => true,
                                'reasoning' => true,
                                'thinking_level_map' => [
                                    'off' => '0',
                                    'minimal' => '0',
                                    'low' => '0',
                                    'medium' => '0',
                                    'high' => '0',
                                    'xhigh' => '0',
                                ],
                                'cost' => ['input' => 0, 'output' => 0],
                            ],
                        ],
                    ],
                ],
            ],
            'extensions' => [
                'enabled' => [
                    'Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension',
                ],
                'settings' => [
                    'safe_guard' => [
                        'tool_names' => [
                            'bash' => 'bash',
                            'write' => 'write',
                            'edit' => 'edit',
                            'read' => 'read',
                        ],
                        'allow_command_patterns' => ['^ls\b', '^printf\b', '^echo\b'],
                        'allow_write_outside_cwd' => [],
                        'protected_read_patterns' => [],
                        'dangerous_command_patterns' => [],
                    ],
                ],
            ],
        ];
    }

    /**
     * Dump replay settings and write both project + HOME copies.
     *
     * @param array<string, mixed> $settings
     */
    public static function writeReplaySettings(string $testProjectDir, array $settings): void
    {
        $yaml = Yaml::dump(self::withSingleLlmWorkerForReplay($settings), 6, 4);
        file_put_contents($testProjectDir.'/.hatfield/settings.yaml', $yaml);

        @mkdir($testProjectDir.'/home/.hatfield', 0o777, true);
        file_put_contents($testProjectDir.'/home/.hatfield/settings.yaml', $yaml);
    }

    private static function shellPrefixWithOptionalMessengerBinary(
        string $appDbPath,
        string $transportDbPath,
        ?string $testProjectDir,
    ): string {
        $prefix = \sprintf(
            'HATFIELD_TEST_DATABASE_PATH=%s HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH=%s ',
            escapeshellarg($appDbPath),
            escapeshellarg($transportDbPath),
        );
        if (null === $testProjectDir || '' === $testProjectDir) {
            return $prefix;
        }

        $wrapper = ControllerReplayLowLatencyMessengerConsole::install(
            $testProjectDir,
            ProjectDir::get().'/bin/console',
        );

        return $prefix.'HATFIELD_BINARY_PATH='.escapeshellarg($wrapper).' ';
    }

    /**
     * @param non-empty-string $from Directory anchor (kernel.project_dir/var/test)
     * @param non-empty-string $to   Target file path
     */
    private static function relativePath(string $from, string $to): string
    {
        $from = str_replace('\\', '/', $from);
        $to = str_replace('\\', '/', $to);
        $fromParts = explode('/', rtrim($from, '/'));
        $toParts = explode('/', $to);
        $fromParts = array_values(array_filter($fromParts, static fn (string $p): bool => '' !== $p));
        $toParts = array_values(array_filter($toParts, static fn (string $p): bool => '' !== $p));

        while ([] !== $fromParts && [] !== $toParts && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        $ups = array_fill(0, \count($fromParts), '..');
        $rel = array_merge($ups, $toParts);

        return implode('/', $rel);
    }
}
