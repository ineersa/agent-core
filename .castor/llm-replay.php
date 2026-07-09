<?php

declare(strict_types=1);

/**
 * LLM Replay Fixture Recording.
 *
 * Castor commands for recording and managing LLM replay fixtures.
 * These commands are opt-in only — no recording runs during normal QA.
 *
 * FIxture format: {@see docs/llm-replay.md}
 *
 * MAINT-05C: Foundation for deterministic LLM replay in CI/QA.
 * MAINT-05D/E will port controller/TUI E2E to replay-backed journeys.
 */

use Castor\Attribute\AsTask;

use function CastorTasks\check_llm_generation_ready;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';
require_once __DIR__.'/env.php';

// ─── Fixture recording ───────────────────────────────────────────

#[AsTask(
    name: 'llm:fixtures:record',
    description: 'Re-record LLM replay fixtures from live llama.cpp/provider',
)]
function llm_fixtures_record(): void
{
    check_llm_generation_ready();

    echo "\n=== LLM Fixture Recording ===\n";
    echo "Recording fixtures from live LLM endpoint.\n";
    echo "Captures stream deltas via StreamRecorderObserver → LlmPlatformAdapter.\n\n";

    $recordingTestClass = 'tests/AgentCore/Infrastructure/SymfonyAi/Replay/ReplayRecordingTest.php';

    if (!file_exists($recordingTestClass)) {
        echo "Recording test not found: {$recordingTestClass}\n";
        echo "Skipping — no recording test defined for this environment.\n";
        exit(0);
    }

    // ── TUI fixture output paths (committed locations) ───────────
    $tuiSimpleFixture = 'tests/Tui/E2E/fixtures/tui-simple-text-response.json';
    $tuiStartupFixture = 'tests/Tui/E2E/fixtures/tui-startup-prompt-response.json';

    $tuiFixtureDir = dirname($tuiSimpleFixture);
    if (!is_dir($tuiFixtureDir)) {
        mkdir($tuiFixtureDir, 0755, true);
    }

    $env = qa_observability_env_command().' APP_ENV=test LLAMA_CPP_SMOKE_TEST=1';
    $env .= ' HATFIELD_RECORD_TUI_SIMPLE_FIXTURE_PATH='.escapeshellarg($tuiSimpleFixture);
    $env .= ' HATFIELD_RECORD_TUI_STARTUP_FIXTURE_PATH='.escapeshellarg($tuiStartupFixture);

    echo "Running recording test (ReplayRecordingTest + TUI fixture methods)...\n\n";

    passthru(
        $env.' '.\PHP_BINARY.' vendor/bin/phpunit'
        .' '.escapeshellarg($recordingTestClass)
        .' --colors=never --no-progress',
        $exitCode,
    );

    if (0 !== $exitCode) {
        echo "\n\nRecording FAILED (exit code {$exitCode}).\n";
        echo "Check that llama.cpp is running on port 9052 with the 'test' model.\n";
        exit(1);
    }

    echo "\n\nRecording complete. Fixtures updated.\n";
    echo "  AgentCore traces → tests/AgentCore/Fixtures/traces/\n";
    echo "  TUI journey reply → {$tuiSimpleFixture}\n";
    echo "  TUI startup reply → {$tuiStartupFixture}\n";
    echo "\nRun 'castor test' and 'castor test:tui' to verify replays pass.\n";
}

// ─── Fixture info / listing ──────────────────────────────────────

#[AsTask(
    name: 'llm:fixtures:info',
    description: 'List available LLM replay fixtures and their metadata',
)]
function llm_fixtures_info(): void
{
    $dir = 'tests/AgentCore/Fixtures/traces/';
    if (!is_dir($dir)) {
        echo "No fixtures directory found at {$dir}\n";
        exit(0);
    }

    $files = glob($dir.'/*.json');
    if (false === $files || [] === $files) {
        echo "No fixtures found in {$dir}\n";
        exit(0);
    }

    echo "\n=== LLM Replay Fixtures ===\n\n";
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            continue;
        }

        $name = basename($file);
        $model = $data['model'] ?? 'unknown';
        $deltas = count($data['deltas'] ?? []);
        $recorded = $data['recorded_at'] ?? 'unknown';
        $hasTools = false;
        foreach ($data['deltas'] ?? [] as $delta) {
            if (in_array($delta['type'] ?? '', ['tool_call_start', 'tool_call_complete'], true)) {
                $hasTools = true;
                break;
            }
        }

        printf("  %-35s model: %-25s deltas: %3d  tools: %s  recorded: %s\n",
            $name,
            $model,
            $deltas,
            $hasTools ? 'yes' : ' no',
            $recorded,
        );
    }
    echo "\n";
}
