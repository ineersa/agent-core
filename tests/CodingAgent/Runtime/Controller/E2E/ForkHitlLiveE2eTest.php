<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live controller E2E for fork child HITL and fork→scout nested HITL contracts.
 *
 * Layer: real controller subprocess + Messenger + live LLM (same as SubagentScoutHitlLiveE2eTest).
 * Parent JSONL stream is the observability surface for direct fork progress/HITL; nested scout
 * may surface HITL on the same stream once the fork child run is registered for forwarding.
 *
 * @group llm-real
 */
#[Group('llm-real')]
final class ForkHitlLiveE2eTest extends ControllerE2eTestCase
{
    private const FORK_AGENT = 'fork';
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

    public function testDirectForkChildSurfacesProgressAndChildHitlOnControllerStream(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_fork_hitl_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => '[llm-real:fork-child-hitl-warmup-v1] Reply with exactly one sentence: "Warmup complete for fork compaction." Do not call any tools.',
            ],
        ]);

        $warmupEvents = $this->collectEvents($this->liveLlmRunWaitTimeout());
        $warmupByType = $this->indexByType($warmupEvents);
        $this->assertStartRunAcked($warmupEvents, $startCmdId);
        $this->assertArrayHasKey('run.started', $warmupByType, $this->collectDiagnostics($warmupEvents));
        $this->runId = (string) ($warmupByType['run.started'][0]['runId'] ?? $warmupByType['run.started'][0]['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId);
        $this->assertTrue(
            isset($warmupByType['run.completed']) || isset($warmupByType['run.failed']),
            'Warmup turn must complete before fork launch (fork compaction needs parent message body). '.$this->collectDiagnostics($warmupEvents),
        );

        $forkTask = 'Call ask_human exactly once with kind "choice" and choices ["alpha","beta"], prompt "Pick one". Do not call bash, subagent, or any other tool.';
        $followCmdId = 'cmd_fork_hitl_follow_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $followCmdId,
            'type' => 'follow_up',
            'runId' => $this->runId,
            'payload' => [
                'text' => '[llm-real:fork-child-hitl-v1] Call tool fork exactly once with JSON arguments {"task":'
                    .json_encode($forkTask, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES).'} '
                    .'Do not call any tool except fork. Do not answer with assistant text before fork returns.',
            ],
        ]);

        $events = $this->collectUntilChildHitlOrTimeout(120.0);
        $byType = $this->indexByType($events);

        $this->assertTrue($this->foundAck($events, $followCmdId), $this->collectDiagnostics($events));
        $parentRunId = $this->parentRunIdForCollection ?? $this->runId;
        $this->assertNotEmpty($parentRunId, $this->collectDiagnostics($events));
        $this->assertArrayHasKey('run.started', $byType, $this->collectDiagnostics($events));

        $this->assertArrayHasKey('tool_execution.started', $byType,
            'Parent must start fork tool. '.$this->collectDiagnostics($events));
        $this->assertNotNull($this->findToolStarted($byType, 'fork'), $this->collectDiagnostics($events));
        $this->assertForkToolDidNotFailBeforeProgress($byType, $events);

        $progressSnapshots = $this->extractSubagentProgressFromEvents($events);
        $this->assertNotEmpty($progressSnapshots,
            'Parent controller stream must emit tool_execution.output_delta with subagent_progress while fork child runs. '
            .$this->collectDiagnostics($events));

        $forkProgress = $this->findProgressForAgent($progressSnapshots, self::FORK_AGENT);
        $this->assertNotNull($forkProgress,
            'subagent_progress must reference fork child. Snapshots: '
            .json_encode($progressSnapshots, \JSON_PRETTY_PRINT)."\n"
            .$this->collectDiagnostics($events));

        $artifactId = (string) ($forkProgress['artifact_id'] ?? '');
        $forkChildRunId = (string) ($forkProgress['agent_run_id'] ?? '');
        $this->assertNotSame('', $artifactId);
        $this->assertNotSame('', $forkChildRunId);

        $registryPath = $this->parentRegistryPath($parentRunId);
        $this->assertFileExists($registryPath, $this->collectDiagnostics($events));
        $registryRaw = (string) file_get_contents($registryPath);
        $this->assertStringContainsString($artifactId, $registryRaw);
        $this->assertStringContainsString(self::FORK_AGENT, $registryRaw);

        $childHitl = $this->findChildHumanInputRequested($events, $parentRunId);
        $this->assertNotNull($childHitl,
            'Fork-owned human_input.requested must appear on parent controller JSONL. '
            .$this->collectDiagnostics($events));

        $hitlRunId = (string) ($childHitl['runId'] ?? $childHitl['payload']['runId'] ?? '');
        $this->assertSame($forkChildRunId, $hitlRunId,
            'HITL event runId must match fork subagent_progress agent_run_id.');

        $hitlPayload = $childHitl['payload'] ?? [];
        if (\is_array($hitlPayload)) {
            $this->assertNotSame('', (string) ($hitlPayload['prompt'] ?? ''));
        }
    }

    public function testForkLaunchesScoutSubagentWithNestedChildHitlOwnership(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_fork_scout_hitl_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => '[llm-real:fork-scout-hitl-warmup-v1] Reply with exactly one sentence: "Warmup complete for fork scout chain." Do not call any tools.',
            ],
        ]);

        $warmupEvents = $this->collectEvents($this->liveLlmRunWaitTimeout());
        $warmupByType = $this->indexByType($warmupEvents);
        $this->assertStartRunAcked($warmupEvents, $startCmdId);
        $this->runId = (string) ($warmupByType['run.started'][0]['runId'] ?? $warmupByType['run.started'][0]['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId);
        $this->assertTrue(isset($warmupByType['run.completed']) || isset($warmupByType['run.failed']),
            $this->collectDiagnostics($warmupEvents));

        $forkTask = 'Call subagent exactly once with JSON {"agent":"'.self::SCOUT_AGENT.'","task":"Follow your scout agent instructions exactly."}. '
            .'Do not call fork or any other tool.';
        $followCmdId = 'cmd_fork_scout_hitl_follow_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $followCmdId,
            'type' => 'follow_up',
            'runId' => $this->runId,
            'payload' => [
                'text' => '[llm-real:fork-scout-hitl-v1] Agent "'.self::SCOUT_AGENT.'" is defined. '
                    .'Call tool fork exactly once with JSON arguments {"task":'
                    .json_encode($forkTask, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES).'} '
                    .'Do not call subagent on the parent run. Only fork.',
            ],
        ]);

        $events = $this->collectUntilNestedScoutHitlOrTimeout(180.0);
        $byType = $this->indexByType($events);

        $this->assertTrue($this->foundAck($events, $followCmdId), $this->collectDiagnostics($events));
        $parentRunId = $this->parentRunIdForCollection ?? $this->runId;
        $this->assertNotEmpty($parentRunId, $this->collectDiagnostics($events));
        $this->assertArrayHasKey('run.started', $byType, $this->collectDiagnostics($events));

        $this->assertNotNull($this->findToolStarted($byType, 'fork'), $this->collectDiagnostics($events));

        $progressSnapshots = $this->extractSubagentProgressFromEvents($events);
        $forkProgress = $this->findProgressForAgent($progressSnapshots, self::FORK_AGENT);
        $this->assertNotNull($forkProgress,
            'Parent stream must show fork progress while fork tool runs. '.$this->collectDiagnostics($events));

        $forkChildRunId = (string) ($forkProgress['agent_run_id'] ?? '');
        $this->assertNotSame('', $forkChildRunId);

        $registryPath = $this->parentRegistryPath($parentRunId);
        $this->assertFileExists($registryPath, $this->collectDiagnostics($events));
        $registryRaw = (string) file_get_contents($registryPath);
        $this->assertStringContainsString(self::FORK_AGENT, $registryRaw);
        $this->assertStringContainsString(self::SCOUT_AGENT, $registryRaw,
            'Parent artifact registry must list nested scout after fork launches it. Registry: '.$registryRaw."\n"
            .$this->collectDiagnostics($events));

        $scoutProgress = $this->findProgressForAgent($progressSnapshots, self::SCOUT_AGENT);
        $scoutRunIdFromStream = null !== $scoutProgress
            ? (string) ($scoutProgress['agent_run_id'] ?? '')
            : '';

        $scoutRunIdFromRegistry = $this->findAgentRunIdInRegistry($registryRaw, self::SCOUT_AGENT);
        $this->assertNotSame('', $scoutRunIdFromRegistry,
            'Registry must include scout agent_run_id. '.$registryRaw);

        $scoutRunId = '' !== $scoutRunIdFromStream ? $scoutRunIdFromStream : $scoutRunIdFromRegistry;
        $this->assertNotSame($parentRunId, $scoutRunId);
        $this->assertNotSame($forkChildRunId, $scoutRunId);

        $childHitl = $this->findHumanInputForRunId($events, $scoutRunId);
        if (null === $childHitl) {
            $childHitl = $this->findChildHumanInputRequestedExcluding($events, $parentRunId, [$forkChildRunId]);
        }

        $this->assertNotNull($childHitl,
            'Nested scout HITL must surface on parent controller JSONL with scout run ownership when fork child is registered for forwarding. '
            .'If missing, inspect fork child artifact events for waiting_human. '
            .$this->collectDiagnostics($events));

        $hitlRunId = (string) ($childHitl['runId'] ?? $childHitl['payload']['runId'] ?? '');
        $this->assertSame($scoutRunId, $hitlRunId,
            'Nested scout HITL runId must match scout agent_run_id, not parent or fork child.');

        $this->assertWaitingHumanInScoutArtifactEvents($scoutRunIdFromRegistry, $registryRaw);
    }

    protected function tempDirPrefix(): string
    {
        return 'test-fork-hitl';
    }

    /** @return list<string> */
    protected function controllerExtraArgs(): array
    {
        return ['--tools=fork,subagent,bash,ask_human'];
    }

    protected function extraSettingsYaml(): string
    {
        return <<<'YAML'
agents:
    enabled: true
    paths:
        - .hatfield/agents/scout.md
forks:
    model: llama_cpp_test/test
compaction:
    model: llama_cpp_test/test
YAML;
    }

    /** @return array<string, string> */
    protected function controllerSubprocessEnv(): array
    {
        return ['HATFIELD_TEST_LLM_HTTP_TIMEOUT' => '180'];
    }

    protected function liveLlmToolWaitTimeout(): float
    {
        return 120.0;
    }

    private function parentRegistryPath(?string $parentRunId = null): string
    {
        $sessionId = $parentRunId ?? $this->parentRunIdForCollection ?? $this->runId;

        return $this->tempDir.'/.hatfield/sessions/'.$sessionId.'/artifacts/agents/registry.json';
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
        return $this->collectUntilPredicate($timeout, function (array $events): bool {
            return null !== $this->findChildHumanInputRequested($events, $this->parentRunIdForCollection ?? $this->runId);
        });
    }

    /** @return list<array<string, mixed>> */
    private function collectUntilNestedScoutHitlOrTimeout(float $timeout): array
    {
        return $this->collectUntilPredicate($timeout, function (array $events): bool {
            if ('' === $this->runId) {
                return false;
            }

            $registryPath = $this->parentRegistryPath();
            if (!is_file($registryPath)) {
                return false;
            }

            $registryRaw = (string) file_get_contents($registryPath);
            if (!str_contains($registryRaw, self::SCOUT_AGENT)) {
                return false;
            }

            $scoutRunId = $this->findAgentRunIdInRegistry($registryRaw, self::SCOUT_AGENT);
            if ('' === $scoutRunId) {
                return false;
            }

            $forkRunId = $this->forkChildRunIdFromEvents($events);
            if (null !== $this->findHumanInputForRunId($events, $scoutRunId)) {
                return true;
            }

            if ('' !== $forkRunId) {
                $hitl = $this->findChildHumanInputRequestedExcluding($events, $this->runId, [$forkRunId]);
                if (null !== $hitl) {
                    $runId = (string) ($hitl['runId'] ?? $hitl['payload']['runId'] ?? '');

                    return $runId === $scoutRunId;
                }
            }

            return false;
        });
    }

    /**
     * @param callable(list<array<string, mixed>>): bool $stopWhen
     *
     * @return list<array<string, mixed>>
     */
    private function collectUntilPredicate(float $timeout, callable $stopWhen): array
    {
        $events = [];
        $deadline = microtime(true) + $timeout;
        $this->parentRunIdForCollection = '' !== $this->runId ? $this->runId : null;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;
                $this->noteParentRunIdFromEvent($event);

                if ($stopWhen($events)) {
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

    /**
     * @param list<string>               $excludeRunIds
     * @param list<array<string, mixed>> $events
     */
    private function findChildHumanInputRequestedExcluding(array $events, string $parentRunId, array $excludeRunIds): ?array
    {
        $exclude = array_fill_keys($excludeRunIds, true);
        foreach ($events as $event) {
            if (($event['type'] ?? '') !== 'human_input.requested') {
                continue;
            }
            $runId = (string) ($event['runId'] ?? $event['payload']['runId'] ?? '');
            if ('' === $runId || $runId === $parentRunId || isset($exclude[$runId])) {
                continue;
            }

            return $event;
        }

        return null;
    }

    /** @param list<array<string, mixed>> $events */
    private function findHumanInputForRunId(array $events, string $expectedRunId): ?array
    {
        foreach ($events as $event) {
            if (($event['type'] ?? '') !== 'human_input.requested') {
                continue;
            }
            $runId = (string) ($event['runId'] ?? $event['payload']['runId'] ?? '');
            if ($runId === $expectedRunId) {
                return $event;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $events */
    private function forkChildRunIdFromEvents(array $events): string
    {
        $progress = $this->findProgressForAgent($this->extractSubagentProgressFromEvents($events), self::FORK_AGENT);

        return null !== $progress ? (string) ($progress['agent_run_id'] ?? '') : '';
    }

    private function findAgentRunIdInRegistry(string $registryRaw, string $agentName): string
    {
        $data = json_decode($registryRaw, true);
        if (!\is_array($data)) {
            return '';
        }
        $entries = $data['entries'] ?? [];
        if (!\is_array($entries)) {
            return '';
        }
        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            if ($agentName !== ($entry['agentName'] ?? $entry['agent_name'] ?? null)) {
                continue;
            }
            $runId = (string) ($entry['agentRunId'] ?? $entry['agent_run_id'] ?? '');

            return $runId;
        }

        return '';
    }

    private function assertWaitingHumanInScoutArtifactEvents(string $scoutArtifactRunId, string $registryRaw): void
    {
        $artifactId = $this->findArtifactIdInRegistry($registryRaw, self::SCOUT_AGENT);
        if ('' === $artifactId) {
            $this->fail('Scout artifact id missing from registry for persisted HITL proof.');
        }

        $eventsPath = $this->tempDir.'/.hatfield/sessions/'.$this->runId
            .'/artifacts/agents/'.$artifactId.'/events.jsonl';
        $this->assertFileExists($eventsPath,
            'Scout child artifact events.jsonl must exist under parent session (TUI backfill/live view path).');

        $raw = (string) file_get_contents($eventsPath);
        $this->assertTrue(
            str_contains($raw, 'waiting_human') || str_contains($raw, 'human_input'),
            'Scout artifact events must record waiting_human / HITL before TUI can render nested live view. Path: '.$eventsPath,
        );
    }

    /**
     * @param array<string, list<array<string, mixed>>> $byType
     * @param list<array<string, mixed>>                $events
     */
    private function assertForkToolDidNotFailBeforeProgress(array $byType, array $events): void
    {
        if (!isset($byType['tool_execution.failed'])) {
            return;
        }

        foreach ($byType['tool_execution.failed'] as $failed) {
            $payload = $failed['payload'] ?? [];
            if (!\is_array($payload)) {
                continue;
            }
            if ('fork' === ($payload['tool_name'] ?? null)) {
                $this->fail('Fork tool failed before progress/HITL could surface: '
                    .json_encode($payload, \JSON_PRETTY_PRINT)."\n".$this->collectDiagnostics($events));
            }
        }
    }

    private function findArtifactIdInRegistry(string $registryRaw, string $agentName): string
    {
        $data = json_decode($registryRaw, true);
        if (!\is_array($data)) {
            return '';
        }
        foreach ($data['entries'] ?? [] as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            if ($agentName === ($entry['agentName'] ?? $entry['agent_name'] ?? null)) {
                return (string) ($entry['artifactId'] ?? $entry['artifact_id'] ?? '');
            }
        }

        return '';
    }
}
