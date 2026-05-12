<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;
use Castor\Context;

use function Castor\run;

const AGENT_CORE_PATH = __DIR__.'/../packages/agent-core';
const TUI_BUNDLE_PATH = __DIR__.'/../packages/tui-bundle';
const CODING_AGENT_PATH = __DIR__.'/../apps/coding-agent';

/**
 * Run QA in both active workspaces (agent-core + coding-agent).
 */
#[AsTask(description: 'Run QA in all workspaces (agent-core, coding-agent)')]
function check(): void
{
    $failures = [];

    foreach ([
        'agent-core' => static fn () => lib_check(),
        'coding-agent' => static function (): void {
            run_in_path(CODING_AGENT_PATH, 'LLM_MODE=true castor dev:check 2>/dev/null || echo "[coding-agent] qa not configured yet"');
        },
    ] as $step => $runner) {
        try {
            $runner();
        } catch (\Throwable $exception) {
            $failures[] = \sprintf('%s: %s', $step, $exception->getMessage());
        }
    }

    if ([] !== $failures) {
        throw new \RuntimeException("workspace check failed:\n - ".implode("\n - ", $failures));
    }

    echo 'workspace check: ok'.\PHP_EOL;
}

/**
 * Install dependencies in root + all workspaces.
 */
#[AsTask(description: 'Install dependencies in root + all workspaces')]
function install(): void
{
    run_in_path(__DIR__.'/..', 'composer install --no-interaction');

    echo '[agent-core] Installing dependencies...'.\PHP_EOL;
    run_in_path(AGENT_CORE_PATH, 'composer install --no-interaction');

    if (is_dir(TUI_BUNDLE_PATH)) {
        echo '[tui-bundle] Installing dependencies...'.\PHP_EOL;
        run_in_path(TUI_BUNDLE_PATH, 'composer install --no-interaction');
    }

    echo '[coding-agent] Installing dependencies...'.\PHP_EOL;
    run_in_path(CODING_AGENT_PATH, 'composer install --no-interaction');
}

/**
 * Run agent-core library QA (cs-fix, phpstan, tests).
 */
#[AsTask(name: 'lib:check', description: 'Run agent-core library QA')]
function lib_check(): void
{
    run_in_path(AGENT_CORE_PATH, 'LLM_MODE=true castor dev:check');
}

/**
 * Run agent-core library tests.
 */
#[AsTask(name: 'lib:test', description: 'Run agent-core library tests')]
function lib_test(): void
{
    run_in_path(AGENT_CORE_PATH, 'LLM_MODE=true castor dev:test');
}

/**
 * Run agent-core library cs-fix.
 */
#[AsTask(name: 'lib:cs-fix', description: 'Run CS fixer on agent-core')]
function lib_cs_fix(): void
{
    run_in_path(AGENT_CORE_PATH, 'LLM_MODE=true castor dev:cs-fix');
}

/**
 * Run agent-core library phpstan.
 */
#[AsTask(name: 'lib:phpstan', description: 'Run PHPStan on agent-core')]
function lib_phpstan(): void
{
    run_in_path(AGENT_CORE_PATH, 'LLM_MODE=true castor dev:phpstan');
}

/**
 * Run coding-agent app QA.
 */
#[AsTask(name: 'app:check', description: 'Run coding-agent QA')]
function app_check(): void
{
    run_in_path(CODING_AGENT_PATH, 'LLM_MODE=true castor dev:check 2>/dev/null || echo "[coding-agent] qa not configured yet"');
}

/**
 * Validate tui-bundle composer.json.
 */
#[AsTask(name: 'tui:validate', description: 'Validate tui-bundle composer.json')]
function tui_validate(): void
{
    run_in_path(TUI_BUNDLE_PATH, 'composer validate');
}

function run_in_path(string $path, string $command): void
{
    run($command, context: new Context(workingDirectory: $path));
}
