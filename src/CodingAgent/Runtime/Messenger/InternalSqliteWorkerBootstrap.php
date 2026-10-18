<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

/**
 * Early entry for Amp ProcessContext workers launched through Hatfield itself.
 *
 * Amp builds argv as:
 *   <binary...> [-dhtml_errors=0 -ddisplay_errors=0 -dlog_errors=1]
 *               [-dxdebug.*=...] process-runner.php <ipc-uri> <key-length>
 *               <timeout> <sqlite-worker.php>
 *
 * Fused natives cannot execute arbitrary PHP scripts, so ProcessContextFactory
 * points the binary at the same Hatfield executable plus --internal-sqlite-worker.
 * This bootstrap accepts only Amp's fixed -d tokens (plus Amp's optional Xdebug
 * copies), validates the IPC triple, then requires the bundled process-runner
 * with the bundled SQLite worker. Caller-supplied runner/worker paths are
 * ignored. Stdin remains Amp's IPC key channel.
 */
final class InternalSqliteWorkerBootstrap
{
    public const string FLAG = '--internal-sqlite-worker';

    /**
     * Exact Amp ProcessContext default -d tokens.
     *
     * @var list<string>
     */
    private const array EXPECTED_PHP_OPTIONS = [
        '-dhtml_errors=0',
        '-ddisplay_errors=0',
        '-dlog_errors=1',
    ];

    /**
     * Amp ProcessContext may copy these Xdebug ini keys into child -d tokens.
     *
     * @var list<string>
     */
    private const array ALLOWED_XDEBUG_INI_KEYS = [
        'xdebug.mode',
        'xdebug.start_with_request',
        'xdebug.client_port',
        'xdebug.client_host',
    ];

    /**
     * @param list<string> $argv
     */
    public static function matches(array $argv): bool
    {
        return null !== self::flagIndex($argv);
    }

    /**
     * @param list<string> $argv
     */
    public static function tryRun(array $argv): never
    {
        $flagIndex = self::flagIndex($argv);
        if (null === $flagIndex) {
            throw new \LogicException('InternalSqliteWorkerBootstrap requires '.self::FLAG.' in argv.');
        }

        $runner = self::bundledProcessRunnerPath();
        $worker = self::bundledSqliteWorkerPath();
        if (!is_file($runner) || !is_readable($runner)) {
            fwrite(\STDERR, "Error: bundled Amp process-runner is missing.\n");
            exit(255);
        }
        if (!is_file($worker) || !is_readable($worker)) {
            fwrite(\STDERR, "Error: bundled Amp SQLite worker is missing.\n");
            exit(255);
        }

        $ampArgv = \array_slice($argv, $flagIndex + 1);
        foreach (self::EXPECTED_PHP_OPTIONS as $expected) {
            $token = array_shift($ampArgv);
            if ($expected !== $token) {
                fwrite(\STDERR, "Error: Amp worker argv must start with the fixed ProcessContext -d options.\n");
                exit(255);
            }
            self::applyPhpOption($token);
        }

        while (isset($ampArgv[0]) && str_starts_with($ampArgv[0], '-d')) {
            $token = array_shift($ampArgv);
            if (!self::isAllowedXdebugOption($token)) {
                fwrite(\STDERR, "Error: Amp worker argv contains an unsupported -d option.\n");
                exit(255);
            }
            self::applyPhpOption($token);
        }

        // Amp may stage a temporary process-runner and may rewrite the worker path
        // when the parent lives inside a PHAR. Accept those path tokens only as
        // placeholders, then require the bundled runner/worker instead.
        if ([] === $ampArgv) {
            fwrite(\STDERR, "Error: Amp process-runner path is missing from worker argv.\n");
            exit(255);
        }
        array_shift($ampArgv);

        if (4 !== \count($ampArgv)) {
            fwrite(\STDERR, "Error: Amp worker argv must be exactly ipc-uri, key-length, timeout, and worker script.\n");
            exit(255);
        }

        $ipcUri = $ampArgv[0];
        $keyLength = $ampArgv[1];
        $timeout = $ampArgv[2];
        if (!self::isValidIpcUri($ipcUri)) {
            fwrite(\STDERR, "Error: Amp IPC URI is invalid.\n");
            exit(255);
        }
        if (!self::isPositiveIntToken($keyLength)) {
            fwrite(\STDERR, "Error: Amp IPC key length must be a positive integer.\n");
            exit(255);
        }
        if (!self::isPositiveIntToken($timeout)) {
            fwrite(\STDERR, "Error: Amp IPC timeout must be a positive integer.\n");
            exit(255);
        }

        $GLOBALS['argv'] = [$runner, $ipcUri, $keyLength, $timeout, $worker];
        $GLOBALS['argc'] = 5;
        $_SERVER['argv'] = $GLOBALS['argv'];
        $_SERVER['argc'] = 5;
        $argv = $GLOBALS['argv'];
        $argc = $GLOBALS['argc'];

        require $runner;
        exit(0);
    }

    public static function bundledProcessRunnerPath(): string
    {
        return \dirname(__DIR__, 4).'/vendor/amphp/parallel/src/Context/Internal/process-runner.php';
    }

    public static function bundledSqliteWorkerPath(): string
    {
        return \dirname(__DIR__, 4).'/vendor/fabpot/amphp-sqlite3/src/Internal/worker.php';
    }

    /**
     * @param list<string> $argv
     */
    private static function flagIndex(array $argv): ?int
    {
        foreach ($argv as $index => $value) {
            if (self::FLAG === $value) {
                return $index;
            }
        }

        return null;
    }

    private static function applyPhpOption(string $token): void
    {
        $assignment = substr($token, 2);
        $eq = strpos($assignment, '=');
        if (false === $eq) {
            throw new \LogicException('Expected Amp -dkey=value token.');
        }
        $key = substr($assignment, 0, $eq);
        $value = substr($assignment, $eq + 1);
        if ('' === $key) {
            throw new \LogicException('Expected non-empty Amp ini key.');
        }
        ini_set($key, $value);
    }

    private static function isAllowedXdebugOption(?string $token): bool
    {
        if (null === $token || !str_starts_with($token, '-d')) {
            return false;
        }
        $assignment = substr($token, 2);
        $eq = strpos($assignment, '=');
        if (false === $eq) {
            return false;
        }
        $key = substr($assignment, 0, $eq);

        return \in_array($key, self::ALLOWED_XDEBUG_INI_KEYS, true);
    }

    private static function isValidIpcUri(string $uri): bool
    {
        if ('' === $uri || str_contains($uri, "\0")) {
            return false;
        }

        return str_starts_with($uri, 'unix://')
            || str_starts_with($uri, 'tcp://')
            || str_starts_with($uri, 'php://');
    }

    private static function isPositiveIntToken(string $token): bool
    {
        return 1 === preg_match('/^[1-9][0-9]*$/', $token);
    }
}
