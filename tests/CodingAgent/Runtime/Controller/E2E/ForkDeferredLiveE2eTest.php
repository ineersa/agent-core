<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Live controller proof: fork tool enters deferred lifecycle and completes once.
 *
 * @group llm-real
 */
#[Group('llm-real')]
final class ForkDeferredLiveE2eTest extends ControllerE2eTestCase
{
    private const CHILD_REPLY = 'FORK_CHILD_DONE';

    /**
     * Delegated task only: one read + report under normal fork instructions.
     * Must NOT restate post-tool finality sequencing — that is the production prompt contract under test.
     */
    private const CHILD_TASK = 'Call tool read exactly once with path ./probe.txt. Report under your normal fork handoff instructions. In section 1 include the exact token '.self::CHILD_REPLY.'.';

    public function testForkToolDeferredCompletionViaLiveController(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_fork_live_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                // Unique first-user tag for llama-proxy cache isolation.
                // Child task asks for one read + normal fork handoff; production prompt must enforce finality.
                'prompt' => '[llm-real:fork-deferred-v4] Call tool fork exactly once with JSON arguments {"task":'.json_encode(self::CHILD_TASK, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES).'}. Do not call any tool except fork.',
            ],
        ]);

        $events = $this->collectEventsUntilDeferredForkCompleted($this->liveLlmDeferredForkToolWaitTimeout());
        $byType = $this->indexByType($events);

        $this->assertStartRunAcked($events, $startCmdId);
        $this->assertArrayHasKey('run.started', $byType, $this->collectDiagnostics($events));
        $runStarted = $byType['run.started'][0] ?? [];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? $this->runId);
        $this->assertNotEmpty($this->runId, 'Parent run id required for artifact path. '.$this->collectDiagnostics($events));

        $this->assertArrayHasKey('tool_execution.started', $byType, $this->collectDiagnostics($events));
        $this->assertSame(
            'fork',
            $byType['tool_execution.started'][0]['payload']['tool_name'] ?? null,
            $this->collectDiagnostics($events),
        );

        $forkCallId = (string) ($byType['tool_execution.started'][0]['payload']['tool_call_id'] ?? '');
        $this->assertNotEmpty($forkCallId);

        $this->assertArrayNotHasKey('tool_execution.failed', $byType, 'fork tool must not fail. '.$this->collectDiagnostics($events));

        $completed = array_values(array_filter(
            $byType['tool_execution.completed'] ?? [],
            static fn (array $e): bool => ($e['payload']['tool_call_id'] ?? '') === $forkCallId,
        ));
        $this->assertCount(1, $completed, 'Exactly one matching fork tool completion expected. '.$this->collectDiagnostics($events));
        $this->assertFalse($completed[0]['payload']['is_error'] ?? true, $this->collectDiagnostics($events));

        $completedPayload = $completed[0]['payload'] ?? [];
        $resultText = (string) ($completedPayload['result'] ?? '');
        if ('' === $resultText) {
            $resultText = json_encode($completedPayload, \JSON_THROW_ON_ERROR);
        }
        $this->assertStringContainsString(self::CHILD_REPLY, $resultText, 'Fork completion must include child reply token. '.$this->collectDiagnostics($events));
        $this->assertStringContainsString('Complete handoff:', $resultText, 'Parent wrapper must keep artifact handoff header. '.$this->collectDiagnostics($events));

        if (1 !== preg_match('/Artifact: (agent_[0-9a-f]{16})/', $resultText, $matches)) {
            $this->fail('Parent fork result must include Artifact: agent_<16 hex>. Result: '.$resultText."\n"
                .$this->collectDiagnostics($events));
        }
        $artifactId = $matches[1];

        $this->assertChildForkStatePostToolFinalHandoff($artifactId, $resultText, $events);
    }

    protected function createIsolatedProjectDir(): void
    {
        parent::createIsolatedProjectDir();
        file_put_contents($this->tempDir.'/probe.txt', "probe-ok\n");
    }

    /**
     * Deferred fork is two sequential LLM calls plus multi-hop Messenger delivery.
     * Align with SubagentParallelLiveE2eTest multi-LLM budget (25s); early-exit collector
     * returns as soon as completion arrives.
     */
    protected function liveLlmDeferredForkToolWaitTimeout(): float
    {
        return 25.0;
    }

    /**
     * Live HttpClient defaults to 5s under APP_ENV=test; multi-LLM deferred paths need more.
     *
     * @return array<string, string>
     */
    protected function controllerSubprocessEnv(): array
    {
        return ['HATFIELD_TEST_LLM_HTTP_TIMEOUT' => '60'];
    }

    protected function controllerExtraArgs(): array
    {
        // Parent allowlist includes read so the fork child can inherit it after fork/subagent are stripped.
        return ['--tools=fork,read'];
    }

    protected function tempDirPrefix(): string
    {
        return 'fork-deferred-live';
    }

    /**
     * Production finality proof via child artifact state under isolated tempDir:
     * read tool call (+result) then last non-empty assistant is structured handoff with no tool_calls.
     *
     * @param list<array<string, mixed>> $events
     */
    private function assertChildForkStatePostToolFinalHandoff(string $artifactId, string $parentResultText, array $events): void
    {
        $registryPath = $this->tempDir.'/.hatfield/sessions/'.$this->runId.'/artifacts/agents/registry.json';
        $this->assertFileExists($registryPath, 'Parent artifact registry must exist after fork. '.$this->collectDiagnostics($events));

        $registryRaw = (string) file_get_contents($registryPath);
        $this->assertStringContainsString($artifactId, $registryRaw, 'Registry must reference fork artifact. '.$this->collectDiagnostics($events));

        $registry = json_decode($registryRaw, true);
        $this->assertIsArray($registry, 'registry.json must decode as JSON object.');
        $agentRunId = $this->agentRunIdFromRegistry($registry, $artifactId);
        $this->assertNotSame('', $agentRunId, 'Registry entry for '.$artifactId.' must include agent_run_id.');

        // Prefer parent-scoped artifact cache; fall back to child session dir when the child
        // run is materialized as a top-level session (ChildAwareRunStore parent-first path).
        $artifactStatePath = $this->tempDir.'/.hatfield/sessions/'.$this->runId.'/artifacts/agents/'.$artifactId.'/state.json';
        $childSessionStatePath = $this->tempDir.'/.hatfield/sessions/'.$agentRunId.'/state.json';
        $statePath = is_file($artifactStatePath) ? $artifactStatePath : $childSessionStatePath;
        $this->assertFileExists(
            $statePath,
            'Child state.json must exist at artifact cache or child session path. Tried: '
            .$artifactStatePath.' and '.$childSessionStatePath.'. '.$this->collectDiagnostics($events),
        );

        $decoded = json_decode((string) file_get_contents($statePath), true);
        $this->assertIsArray($decoded, 'Child state.json must be JSON object.');
        $messages = $decoded['messages'] ?? null;
        $this->assertIsArray($messages, 'Child state must include messages[].');

        $sawReadToolCall = false;
        $readToolResultIndex = null;
        $lastNonEmptyAssistantIndex = null;
        $lastNonEmptyAssistantText = '';
        $lastNonEmptyAssistantToolCalls = [];

        foreach ($messages as $index => $message) {
            if (!\is_array($message)) {
                continue;
            }

            $role = (string) ($message['role'] ?? '');
            if ('assistant' === $role) {
                $toolCalls = $this->assistantToolCalls($message);
                foreach ($toolCalls as $toolCall) {
                    if (!\is_array($toolCall)) {
                        continue;
                    }
                    if ('read' === ($toolCall['name'] ?? null)) {
                        $sawReadToolCall = true;
                        // A later read call invalidates prior result-index until a new tool result arrives.
                        $readToolResultIndex = null;
                    }
                }

                $text = $this->assistantTextContent($message);
                if ('' !== trim($text)) {
                    $lastNonEmptyAssistantIndex = (int) $index;
                    $lastNonEmptyAssistantText = $text;
                    $lastNonEmptyAssistantToolCalls = $toolCalls;
                }
            }

            if ('tool' === $role && $sawReadToolCall && 'read' === ($message['tool_name'] ?? null)) {
                $readToolResultIndex = (int) $index;
            }
        }

        $this->assertTrue($sawReadToolCall, 'Child state must contain a read tool call. '.$this->collectDiagnostics($events));
        $this->assertNotNull($readToolResultIndex, 'Child state must contain a read tool result after the call. '.$this->collectDiagnostics($events));
        $this->assertNotNull($lastNonEmptyAssistantIndex, 'Child state must contain a non-empty final assistant message. '.$this->collectDiagnostics($events));
        $this->assertGreaterThan(
            $readToolResultIndex,
            $lastNonEmptyAssistantIndex,
            'Final non-empty assistant handoff must appear after the read tool result.',
        );
        $this->assertSame([], $lastNonEmptyAssistantToolCalls, 'Final child assistant message must not request tools (post-tool finality). Text head: '.substr($lastNonEmptyAssistantText, 0, 200));
        $this->assertStringContainsString(
            '## 1. Result / status',
            $lastNonEmptyAssistantText,
            'Final child assistant message must be structured Pi handoff section 1. Text head: '.substr($lastNonEmptyAssistantText, 0, 300),
        );
        $this->assertStringContainsString(
            self::CHILD_REPLY,
            $lastNonEmptyAssistantText,
            'Final child assistant message must include marker token. Text head: '.substr($lastNonEmptyAssistantText, 0, 300),
        );

        // Parent completion wraps the final handoff body (not a later recap).
        $this->assertStringContainsString(
            $lastNonEmptyAssistantText,
            $parentResultText,
            'Parent completion must wrap the exact final child handoff text.',
        );
    }

    /**
     * @param array<string, mixed> $registry
     */
    private function agentRunIdFromRegistry(array $registry, string $artifactId): string
    {
        $entries = $registry['entries'] ?? null;
        if (!\is_array($entries)) {
            return '';
        }

        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            if (($entry['artifact_id'] ?? null) !== $artifactId) {
                continue;
            }
            $agentRunId = $entry['agent_run_id'] ?? null;

            return \is_string($agentRunId) ? $agentRunId : '';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return list<array<string, mixed>>
     */
    private function assistantToolCalls(array $message): array
    {
        $metadata = $message['metadata'] ?? [];
        if (!\is_array($metadata)) {
            return [];
        }

        $toolCalls = $metadata['tool_calls'] ?? [];
        if (!\is_array($toolCalls)) {
            return [];
        }

        $normalized = [];
        foreach ($toolCalls as $toolCall) {
            if (\is_array($toolCall)) {
                $normalized[] = $toolCall;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function assistantTextContent(array $message): string
    {
        $content = $message['content'] ?? [];
        if (!\is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $part) {
            if (!\is_array($part)) {
                continue;
            }
            if ('text' === ($part['type'] ?? null) && \is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        return implode('', $parts);
    }

    /**
     * Deferred fork can complete after parent run.completed; wait by tool_call_id only.
     *
     * Local to this live smoke — does not change shared ControllerE2eTestCase collectors.
     *
     * @return list<array<string, mixed>>
     */
    private function collectEventsUntilDeferredForkCompleted(float $timeout): array
    {
        $events = [];
        $targetToolCallIds = [];
        $deadline = microtime(true) + $timeout;
        $this->parentRunIdForCollection = '' !== $this->runId ? $this->runId : null;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;
                $this->noteParentRunIdFromEvent($event);

                $type = $event['type'] ?? '';
                $payload = $event['payload'] ?? [];
                if (!\is_array($payload)) {
                    $payload = [];
                }

                if ('tool_execution.started' === $type
                    && 'fork' === ($payload['tool_name'] ?? null)
                    && isset($payload['tool_call_id'])
                ) {
                    $targetToolCallIds[(string) $payload['tool_call_id']] = true;
                }

                if ('tool_execution.completed' === $type
                    && isset($payload['tool_call_id'])
                    && isset($targetToolCallIds[(string) $payload['tool_call_id']])
                ) {
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
}
