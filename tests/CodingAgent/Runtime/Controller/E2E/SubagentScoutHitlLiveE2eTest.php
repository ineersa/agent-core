<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live controller E2E: direct foreground scout subagent reaches child HITL and
 * parent JSONL stream exposes subagent_progress + child-owned human_input.requested.
 *
 * @group llm-real
 */
#[Group('llm-real')]
final class SubagentScoutHitlLiveE2eTest extends ControllerE2eTestCase
{
    private const SCOUT_AGENT = 'scout';

    protected function setUp(): void
    {
        parent::setUp();

        $docsDir = $this->tempDir.'/docs';
        TestDirectoryIsolation::ensureDirectory($docsDir, 0o777);
        file_put_contents($docsDir.'/settings.md', "# Settings\n");
        file_put_contents($docsDir.'/agents.md', "# Agents\n");

        $agentsDir = $this->tempDir.'/.hatfield/agents';
        TestDirectoryIsolation::ensureDirectory($agentsDir, 0o777);
        $this->writeScoutAgent($agentsDir.'/scout.md');
    }

    public function testDirectScoutSubagentSurfacesProgressAndChildHitlOnControllerStream(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_scout_hitl_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => '[llm-real:subagent-scout-hitl-v1] Agent "'.self::SCOUT_AGENT.'" is defined in this project. '
                    .'Call tool subagent exactly once with JSON arguments {"agent":"'.self::SCOUT_AGENT.'","task":"Follow your agent instructions exactly. Use bash once to list docs/, then ask_human once with kind choice."}. '
                    .'Do not call any tool except subagent. Do not answer with assistant text before subagent returns.',
            ],
        ]);

        $events = $this->collectUntilChildHitlOrTimeout(90.0);
        $byType = $this->indexByType($events);

        $this->assertStartRunAcked($events, $startCmdId);
        $this->assertArrayHasKey('run.started', $byType, $this->collectDiagnostics($events));

        $runStarted = $byType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId, $this->collectDiagnostics($events));

        $this->assertArrayHasKey('tool_execution.started', $byType,
            'Parent must start subagent tool. '.$this->collectDiagnostics($events));
        $subagentStarted = $this->findToolStarted($byType, 'subagent');
        $this->assertNotNull($subagentStarted, $this->collectDiagnostics($events));

        $progressSnapshots = $this->extractSubagentProgressFromEvents($events);
        $this->assertNotEmpty($progressSnapshots,
            'Parent controller stream must emit tool_execution.output_delta with subagent_progress while scout runs. '
            .$this->collectDiagnostics($events));

        $scoutProgress = $this->findProgressForAgent($progressSnapshots, self::SCOUT_AGENT);
        $this->assertNotNull($scoutProgress,
            'subagent_progress must reference scout child. Snapshots: '
            .json_encode($progressSnapshots, \JSON_PRETTY_PRINT)."\n"
            .$this->collectDiagnostics($events));

        $artifactId = (string) ($scoutProgress['artifact_id'] ?? '');
        $childRunId = (string) ($scoutProgress['agent_run_id'] ?? '');
        $this->assertNotSame('', $artifactId, 'Catalog/agents-live requires artifact_id on progress.');
        $this->assertNotSame('', $childRunId, 'Child HITL routing requires agent_run_id on progress.');

        $registryPath = $this->tempDir.'/.hatfield/sessions/'.$this->runId.'/artifacts/agents/registry.json';
        $this->assertFileExists($registryPath,
            'Parent artifact registry must exist after subagent launch. '.$this->collectDiagnostics($events));
        $registryRaw = (string) file_get_contents($registryPath);
        $this->assertStringContainsString($artifactId, $registryRaw, $registryRaw);
        $this->assertStringContainsString(self::SCOUT_AGENT, $registryRaw, $registryRaw);

        $childHitl = $this->findChildHumanInputRequested($events, $this->runId);
        $this->assertNotNull($childHitl,
            'Child-owned human_input.requested must appear on parent controller JSONL. '
            .$this->collectDiagnostics($events));

        $hitlRunId = (string) ($childHitl['runId'] ?? $childHitl['payload']['runId'] ?? '');
        $this->assertSame($childRunId, $hitlRunId,
            'HITL event runId must match subagent_progress agent_run_id.');

        $hitlPayload = $childHitl['payload'] ?? [];
        if (\is_array($hitlPayload)) {
            $this->assertNotSame('', (string) ($hitlPayload['prompt'] ?? ''), 'HITL payload must include prompt.');
        }
    }

    protected function tempDirPrefix(): string
    {
        return 'test-subagent-scout-hitl';
    }

    /** @return list<string> */
    protected function controllerExtraArgs(): array
    {
        return ['--tools=subagent,bash,ask_human'];
    }

    protected function extraSettingsYaml(): string
    {
        return <<<'YAML'
agents:
    enabled: true
    paths:
        - .hatfield/agents/scout.md
YAML;
    }

    /** @return array<string, string> */
    protected function controllerSubprocessEnv(): array
    {
        return ['HATFIELD_TEST_LLM_HTTP_TIMEOUT' => '120'];
    }

    protected function liveLlmToolWaitTimeout(): float
    {
        return 90.0;
    }

    private function writeScoutAgent(string $path): void
    {
        $content = <<<'MD'
---
name: scout
description: "Live E2E scout for bash + ask_human HITL"
tools:
  - bash
  - ask_human
mcp:
  mode: none
inheritProjectContext: false
inheritAgentsMd: false
foregroundAllowed: true
backgroundAllowed: false
parallelAllowed: false
disabled: false
---
You are a delegated scout child. Execute exactly:
1. Call bash once with command `ls docs`.
2. Call ask_human once with kind "choice", presenting the listed doc filenames as choices, asking which file to summarize.
Do not call subagent or any other tool.
MD;

        if (false === file_put_contents($path, $content)) {
            throw new \RuntimeException('Failed to write scout agent: '.$path);
        }
    }

    /** @return list<array<string, mixed>> */
    private function collectUntilChildHitlOrTimeout(float $timeout): array
    {
        $events = [];
        $deadline = microtime(true) + $timeout;
        $this->parentRunIdForCollection = '' !== $this->runId ? $this->runId : null;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;
                $this->noteParentRunIdFromEvent($event);

                if (null !== $this->findChildHumanInputRequested($events, $this->parentRunIdForCollection ?? '')) {
                    return $events;
                }

                if ($this->isParentRunTerminalEvent($event)) {
                    return $events;
                }
            }

            if (!$this->isRunning()) {
                foreach ($this->readEvents() as $event) {
                    $events[] = $event;
                }
                break;
            }

            usleep(10_000);
        }

        return $events;
    }

    /** @param array<string, list<array<string, mixed>>> $byType */
    private function findToolStarted(array $byType, string $toolName): ?array
    {
        foreach ($byType['tool_execution.started'] ?? [] as $started) {
            if ($toolName === ($started['payload']['tool_name'] ?? null)) {
                return $started;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $events */
    private function extractSubagentProgressFromEvents(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            if (($event['type'] ?? '') !== 'tool_execution.output_delta') {
                continue;
            }
            $payload = $event['payload'] ?? [];
            if (!\is_array($payload)) {
                continue;
            }
            $progress = $payload['subagent_progress'] ?? null;
            if (\is_array($progress)) {
                $out[] = $progress;
            }
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $snapshots */
    private function findProgressForAgent(array $snapshots, string $agentName): ?array
    {
        foreach ($snapshots as $progress) {
            if ($agentName === ($progress['agent_name'] ?? null)) {
                return $progress;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $events */
    private function findChildHumanInputRequested(array $events, string $parentRunId): ?array
    {
        foreach ($events as $event) {
            if (($event['type'] ?? '') !== 'human_input.requested') {
                continue;
            }
            $runId = (string) ($event['runId'] ?? $event['payload']['runId'] ?? '');
            if ('' === $runId || ('' !== $parentRunId && $runId === $parentRunId)) {
                continue;
            }

            return $event;
        }

        return null;
    }
}
