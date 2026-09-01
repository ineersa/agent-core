<?php

declare(strict_types=1);

/**
 * LLM Replay Fixture Inspection.
 *
 * Castor commands for inspecting committed LLM replay fixtures.
 * Replay helpers live under tests/AgentCore/Infrastructure/SymfonyAi/Replay/.
 * Fixture format: {@see docs/llm-replay.md}
 */

use Castor\Attribute\AsTask;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';
require_once __DIR__.'/env.php';

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
