<?php

declare(strict_types=1);

/**
 * Environment, diagnostics, and Datadog tasks.
 *
 * Diagnostic commands for Hatfield settings, app info, env vars,
 * Datadog APM/log configuration and smoke-testing.  Also includes
 * shared env helpers used by agent-runtime tasks (run:agent).
 */

use Castor\Attribute\AsTask;

use function CastorTasks\build_idea_run_config_xml;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';

// ─── Datadog helpers ────────────────────────────────────────────

/**
 * Whether the default launcher should enable Datadog APM for the spawned
 * agent process.
 *
 * Auto mode enables APM only when ddtrace is installed and a local Agent trace
 * endpoint is reachable. Set HATFIELD_DATADOG=0 to force-disable or
 * HATFIELD_DATADOG=1 to force-enable when ddtrace is loaded.
 */
function datadog_auto_enabled(): bool
{
    $flag = getenv('HATFIELD_DATADOG');
    if (false !== $flag) {
        return in_array(strtolower($flag), ['1', 'true', 'yes', 'on'], true) && extension_loaded('ddtrace');
    }

    if (!extension_loaded('ddtrace')) {
        return false;
    }

    if (false !== getenv('DD_TRACE_ENABLED') && in_array(strtolower((string) getenv('DD_TRACE_ENABLED')), ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return datadog_trace_endpoint_available();
}

function datadog_is_unix_socket(string $path): bool
{
    return file_exists($path) && 'socket' === @filetype($path);
}

function datadog_trace_endpoint_available(): bool
{
    $agentUrl = getenv('DD_TRACE_AGENT_URL');
    if (is_string($agentUrl) && str_starts_with($agentUrl, 'unix://')) {
        return datadog_is_unix_socket(substr($agentUrl, strlen('unix://')));
    }

    if (datadog_is_unix_socket('/var/run/datadog/apm.socket')) {
        return true;
    }

    $host = false !== getenv('DD_AGENT_HOST') ? (string) getenv('DD_AGENT_HOST') : '127.0.0.1';
    $port = (int) (false !== getenv('DD_TRACE_AGENT_PORT') ? (string) getenv('DD_TRACE_AGENT_PORT') : '8126');
    $socket = @fsockopen((string) $host, $port, $errno, $errstr, 0.1);
    if (is_resource($socket)) {
        fclose($socket);

        return true;
    }

    return false;
}

/**
 * Environment prefix for Datadog APM opt-in/opt-out when launching PHP.
 *
 * ddtrace reads its settings before userland PHP boots, so these values must
 * be present in the shell environment that starts `php bin/console`.
 */

/**
 * Environment prefix for Castor QA/test PHP child processes.
 *
 * Disables optional APM/log injection so PHPUnit assertions on PSR-3 message
 * strings stay deterministic when a host loads tracing extensions. Harmless
 * when no extension is present.
 */
function qa_observability_env_command(): string
{
    $base = datadog_env_command(false);

    return $base.' DD_LOGS_INJECTION=0 DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=false';
}

/**
 * Shell env prefix for castor check lanes: QA run-scoped paths plus observability.
 *
 * When HATFIELD_QA_RUN_ID is unset (non-check commands), this degrades to
 * qa_observability_env_command() only.
 */
function qa_check_run_env_command(): string
{
    $obs = qa_observability_env_command();
    $runId = getenv('HATFIELD_QA_RUN_ID');
    if (false === $runId || '' === trim((string) $runId)) {
        return $obs;
    }

    $pairs = [];
    foreach ([
        'HATFIELD_QA_RUN_ID',
        'HATFIELD_QA_REPORTS_DIR',
        'HATFIELD_QA_TMP_DIR',
        'HATFIELD_CACHE_DIR',
        'HATFIELD_TEST_DATABASE_PATH',
        'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH',
    ] as $name) {
        $value = getenv($name);
        if (false === $value || '' === trim((string) $value)) {
            continue;
        }
        $pairs[] = $name.'='.escapeshellarg((string) $value);
    }

    if ([] === $pairs) {
        return $obs;
    }

    if (!str_starts_with($obs, 'env ')) {
        return $obs.' env '.implode(' ', $pairs);
    }

    return 'env '.implode(' ', $pairs).' '.substr($obs, 4);
}

function datadog_env_command(bool $enabled): string
{
    $vars = [
        'DD_TRACE_ENABLED' => $enabled ? '1' : '0',
        'DD_TRACE_CLI_ENABLED' => $enabled ? '1' : '0',
    ];

    if ($enabled) {
        $version = trim(shell_exec('git rev-parse --short HEAD 2>/dev/null') ?? '');
        $vars += [
            'DD_SERVICE' => (false !== getenv('DD_SERVICE') ? (string) getenv('DD_SERVICE') : 'hatfield'),
            'DD_ENV' => (false !== getenv('DD_ENV') ? (string) getenv('DD_ENV') : 'dev'),
            'DD_VERSION' => '' !== $version ? $version : 'local',
            'DD_LOGS_INJECTION' => 'true',
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS' => 'true',
        ];

        if (datadog_is_unix_socket('/var/run/datadog/apm.socket')) {
            $vars['DD_TRACE_AGENT_URL'] = 'unix:///var/run/datadog/apm.socket';
        }
    }

    $parts = ['env'];
    foreach ($vars as $name => $value) {
        $parts[] = $name.'='.escapeshellarg($value);
    }

    return implode(' ', $parts);
}

// ─── Diagnostics ──────────────────────────────────────────────

/**
 * Show Hatfield settings sourcing, basic app info, and env vars.
 */
#[AsTask(name: 'diagnostic', namespace: 'diag', description: 'Show Hatfield settings sourcing, basic app info, and env vars')]
function diagnostic(): void
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');

    echo 'PHP: '.\PHP_VERSION.' '.\PHP_BINARY.\PHP_EOL;
    echo 'SAPI: '.\PHP_SAPI.\PHP_EOL;
    echo 'OS: '.\PHP_OS.\PHP_EOL;
    echo 'CWD: '.(string) getcwd().\PHP_EOL;
    echo 'Root: '.$root.\PHP_EOL;
    echo \PHP_EOL;

    echo "Hatfield settings files (built-in defaults → ~/.hatfield/ → project):\n";
    echo '  1. config/hatfield.defaults.yaml '.(is_readable($root.'/config/hatfield.defaults.yaml') ? 'present' : 'missing').\PHP_EOL;
    echo '  2. ~/.hatfield/settings.yaml       '.(is_readable($_SERVER['HOME'].'/.hatfield/settings.yaml') ? 'present' : 'missing').\PHP_EOL;
    echo '  3.  ./.hatfield/settings.yaml       '.(is_readable($root.'/.hatfield/settings.yaml') ? 'present' : 'missing').\PHP_EOL;
    echo \PHP_EOL;

    echo "Environment:\n";
    $vars = ['APP_ENV', 'APP_DEBUG', 'DATABASE_URL', 'HATFIELD_CWD'];
    foreach ($vars as $var) {
        $val = $_SERVER[$var] ?? $_ENV[$var] ?? null;
        if (null !== $val && str_contains($var, 'DATABASE')) {
            // Redact DB credentials in the path portion.
            $val = preg_replace('{://[^@]+@}', '://REDACTED@', $val);
        }
        echo '  '.$var.'='.($val ?? '(unset)').\PHP_EOL;
    }
    echo \PHP_EOL;

    echo "Symfony debug:\n";
    echo '  env: '.App\Kernel::env().\PHP_EOL;
    $paths = App\Kernel::hatfieldConfigPaths();
    echo '  config paths: '.implode(' : ', $paths).\PHP_EOL;

    try {
        App\Kernel::boot();
    } catch (Throwable $e) {
        echo '  boot error: '.$e->getMessage().\PHP_EOL;
    }

    echo \PHP_EOL;

    $homeDir = $_SERVER['HOME'];
    echo "Home: {$homeDir}\n";
    $globalDir = App\Kernel::resolveGlobalHatfieldDir();
    echo 'Global dir: '.($globalDir ?? '(null)').\PHP_EOL;
    if (null !== $globalDir) {
        echo 'Global dir readable: '.(is_readable($globalDir) ? 'yes' : 'no').\PHP_EOL;
    }
    echo \PHP_EOL;
}

#[AsTask(name: 'diagnostic:full', namespace: 'diag', description: 'Show full Hatfield diagnostics')]
function diagnostic_full(): void
{
    diagnostic();
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');

    echo "Hatfield tree:\n";
    try {
        App\Kernel::boot();
    } catch (Throwable $e) {
        echo '  boot error: '.$e->getMessage().\PHP_EOL;
    }
}

// ─── Datadog tasks ──────────────────────────────────────────────

#[AsTask(name: 'datadog:smoke', description: 'Show Datadog smoke diagnostic')]
function datadog_smoke_diag(): void
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    $today = date('Y-m-d');
    $todayLog = "{$root}/.hatfield/logs/agent-{$today}.log";
    $installedConfig = '/etc/datadog-agent/conf.d/hatfield.d/conf.yaml';
    $legacyConfig = '/etc/datadog-agent/conf.d/conf.yaml';

    echo 'Datadog smoke diagnostic'.\PHP_EOL;
    echo \PHP_EOL;

    echo 'Package: '.(false !== ($_v = shell_exec('dpkg -l datadog-agent 2>/dev/null | grep ^ii')) ? trim($_v) : 'not installed').\PHP_EOL;
    echo 'Datadog agent: '.(false !== ($_v = shell_exec('systemctl is-active datadog-agent 2>/dev/null')) ? trim($_v) : 'unknown').\PHP_EOL;
    echo \PHP_EOL;

    echo "PHP extension:\n";
    echo '  ddtrace: '.(extension_loaded('ddtrace') ? 'yes' : 'no').\PHP_EOL;
    if (extension_loaded('ddtrace')) {
        echo '  ddtrace cli enabled: '.(false !== ($_v = ini_get('datadog.trace.cli_enabled')) ? $_v : '(default)').\PHP_EOL;
        echo '  ddtrace enabled: '.(false !== ($_v = ini_get('datadog.trace.enabled')) ? $_v : '(default)').\PHP_EOL;
        echo '  ddtrace service: '.(false !== ($_v = ini_get('datadog.service')) ? $_v : '(unset)').\PHP_EOL;
        echo '  ddtrace env: '.(false !== ($_v = ini_get('datadog.env')) ? $_v : '(unset)').\PHP_EOL;
        echo '  ddtrace agent_url: '.(false !== ($_v = ini_get('datadog.trace.agent_url')) ? $_v : '(default)').\PHP_EOL;
    }

    echo 'Hatfield log today: '.$todayLog.' '.(is_readable($todayLog) ? 'readable' : 'missing/not-readable').\PHP_EOL;
    echo 'Expected Agent config: '.$installedConfig.' '.(is_readable($installedConfig) ? 'present' : 'missing/not-readable').\PHP_EOL;
    if (is_readable($legacyConfig)) {
        echo 'Legacy config warning: '.$legacyConfig.' exists; prefer conf.d/hatfield.d/conf.yaml'.\PHP_EOL;
    }

    echo \PHP_EOL.'Install/check commands:'.\PHP_EOL;
    echo '  castor datadog:log-config'.\PHP_EOL;
    echo '  sudo systemctl restart datadog-agent'.\PHP_EOL;
    echo '  castor datadog:smoke-log'.\PHP_EOL;
}

#[AsTask(name: 'datadog:log-config', description: 'Print the Datadog Agent Hatfield log config and install hints')]
function datadog_log_config(): void
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    $config = $root.'/ops/datadog/hatfield.d/conf.yaml';

    echo file_get_contents($config);
    echo \PHP_EOL.'Install with:'.\PHP_EOL;
    echo '  sudo mkdir -p /etc/datadog-agent/conf.d/hatfield.d'.\PHP_EOL;
    echo '  sudo install -o dd-agent -g dd-agent -m 0644 ops/datadog/hatfield.d/conf.yaml /etc/datadog-agent/conf.d/hatfield.d/conf.yaml'.\PHP_EOL;
    echo '  sudo rm -f /etc/datadog-agent/conf.d/conf.yaml'.\PHP_EOL;
    echo '  setfacl -m u:dd-agent:--x /home/ineersa'.\PHP_EOL;
    echo '  setfacl -m u:dd-agent:rX /home/ineersa/projects/agent-core/.hatfield/logs'.\PHP_EOL;
    echo '  setfacl -m u:dd-agent:rX /home/ineersa/projects/agent-core-worktrees 2>/dev/null || true'.\PHP_EOL;
    echo '  sudo systemctl restart datadog-agent'.\PHP_EOL;
}

#[AsTask(name: 'datadog:smoke-log', description: 'Write a Datadog log collection smoke-test line')]
function datadog_smoke_log(): void
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    $logDir = $root.'/.hatfield/logs';
    if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
        throw new RuntimeException(sprintf('Unable to create log directory "%s".', $logDir));
    }

    $message = 'datadog smoke '.date(\DATE_ATOM).' '.bin2hex(random_bytes(4));
    $line = json_encode([
        'message' => $message,
        'context' => ['component' => 'datadog:smoke-log'],
        'level' => 200,
        'level_name' => 'INFO',
        'channel' => 'app',
        'datetime' => date(\DATE_ATOM),
        'extra' => ['service' => 'hatfield', 'env' => 'dev'],
    ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

    $path = $logDir.'/agent-'.date('Y-m-d').'.log';
    file_put_contents($path, $line.\PHP_EOL, \FILE_APPEND | \LOCK_EX);

    echo 'Wrote smoke log line to '.project_relative_path($path).\PHP_EOL;
    echo 'Search Datadog Logs Explorer for: "'.$message.'"'.\PHP_EOL;
}

// ─── IDE helpers ──────────────────────────────────────────────────

#[AsTask(name: 'ide:config', description: 'Generate IDE run configuration XML')]
function ide_config(): void
{
    echo build_idea_run_config_xml();
}
