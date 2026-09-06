<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests\Controller;

use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\ControllerReplayE2eTestCase;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use PHPUnit\Framework\Attributes\Group;

/**
 * Controller-subprocess proof for interactive session-start eligibility:
 * - missing .idea disables before any turn;
 * - resume of the same session id recovers from a persisted Disabled state
 *   through the real extension_agent transport and wrapper code_search path.
 *
 * Isolated project trees never invoke first-index creation. Recovery with an
 * existing snapshot uses a stub jbcontext binary on PATH that answers status /
 * search and refuses index.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayJbcontextStartupEligibilityTest extends ControllerReplayE2eTestCase
{
    private const string MISSING_IDEA_STATUS = 'jbcontext disabled: project has no .idea directory. Open the project in JetBrains IDE and run jbcontext index manually before enabling search.';
    private const string TOOL_CALL_ID = 'call_jbctx_1';
    private const string QUERY = 'How does Hatfield prevent two processes from running the same session concurrently?';

    private string $binDir = '';

    public function testControllerStartupDisablesEligibilityWithoutIdeaBeforeAnyTurn(): void
    {
        $this->assertDirectoryDoesNotExist($this->tempDir.'/.idea');

        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $state = $this->waitForTerminalStatus(static fn (JbcontextSessionState $candidate): bool => JbcontextSessionModeEnum::Disabled === $candidate->mode
            && self::MISSING_IDEA_STATUS === $candidate->statusText
            && true === $candidate->eligibilityStarted
            && $candidate->checkGeneration >= 1);

        $this->assertSame(JbcontextSessionModeEnum::Disabled, $state->mode);
        $this->assertSame(self::MISSING_IDEA_STATUS, $state->statusText);
        $this->assertTrue($state->eligibilityStarted);
        $this->assertGreaterThanOrEqual(1, $state->checkGeneration);
        $this->assertDirectoryDoesNotExist($this->tempDir.'/.idea');
        $this->assertDirectoryDoesNotExist($this->tempDir.'/.hatfield/skills/jbcontext-semantic-search');
        $this->assertFileDoesNotExist($this->tempDir.'/.hatfield/agents/scout.md');
    }

    public function testControllerRestartRecoversStaleDisabledToEligibleAndCodeSearch(): void
    {
        $this->installStubJbcontext();
        mkdir($this->tempDir.'/.idea', 0o777, true);

        $paths = JbcontextPaths::fromProjectRoot($this->tempDir);
        JbcontextStatusStore::forSession($paths, $this->sessionId)->write(new JbcontextSessionState(
            sessionId: $this->sessionId,
            mode: JbcontextSessionModeEnum::Disabled,
            reason: 'jbcontext disabled: status check failed after retries. Fix CLI auth/daemon access and restart Hatfield.',
            statusText: 'jbcontext disabled: status check failed after retries. Fix CLI auth/daemon access and restart Hatfield.',
            attempt: 5,
            startedAt: 1.0,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            checkGeneration: 1,
            updatedAt: 1.0,
        ));

        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $state = $this->waitForTerminalStatus(static fn (JbcontextSessionState $candidate): bool => JbcontextSessionModeEnum::Eligible === $candidate->mode
            && true === $candidate->eligibilityStarted
            && 2 === $candidate->checkGeneration
            && null === $candidate->reason
            && str_starts_with((string) $candidate->statusText, 'jbcontext: indexed'));

        $this->assertSame(JbcontextSessionModeEnum::Eligible, $state->mode);
        $this->assertSame(2, $state->checkGeneration);
        $this->assertNull($state->reason);
        $this->assertStringStartsWith('jbcontext: indexed', (string) $state->statusText);
        $this->assertNotSame([], $this->stubLogLines());
        $this->assertTrue(
            (bool) array_filter(
                $this->stubLogLines(),
                static fn (string $line): bool => str_starts_with($line, 'status'),
            ),
            'Recovery must call jbcontext status.',
        );

        $startCmdId = 'cmd_start_'.uniqid('', true);
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            // Bind the run to the controller session id so tool ambient runId
            // resolves the same jbcontext status file that session-start wrote.
            // Opaque HATFIELD_SESSION_ID labels are not auto-promoted to run ids.
            'runId' => $this->sessionId,
            'payload' => [
                'prompt' => 'Call the tool named code_search exactly once with text '
                    .json_encode(self::QUERY, \JSON_THROW_ON_ERROR)
                    .'. Do not call any other tool. After the tool succeeds, answer exactly done.',
            ],
        ]);

        $events = $this->collectEventsUntil('tool_execution.completed', 8.0);
        $byType = $this->indexByType($events);
        $this->assertStartRunAcked($events, $startCmdId);
        $this->assertArrayHasKey('run.started', $byType, $this->collectDiagnostics($events));
        $runId = (string) ($byType['run.started'][0]['runId'] ?? $byType['run.started'][0]['payload']['runId'] ?? '');
        $this->assertNotSame('', $runId, $this->collectDiagnostics($events));
        $this->assertSame(
            $this->sessionId,
            $runId,
            'Hatfield session_id must equal run_id so jbcontext session status matches tool ambient runId. '
            .$this->collectDiagnostics($events),
        );
        $this->assertArrayHasKey('tool_execution.started', $byType, $this->collectDiagnostics($events));
        $this->assertSame('code_search', $byType['tool_execution.started'][0]['payload']['tool_name'] ?? null);
        $this->assertSame(self::TOOL_CALL_ID, $byType['tool_execution.started'][0]['payload']['tool_call_id'] ?? null);
        $this->assertArrayHasKey('tool_execution.completed', $byType, $this->collectDiagnostics($events));
        $this->assertArrayNotHasKey('tool_execution.failed', $byType, $this->collectDiagnostics($events));

        $result = (string) ($byType['tool_execution.completed'][0]['payload']['result'] ?? '');
        $statusAfter = JbcontextStatusStore::forSession(
            JbcontextPaths::fromProjectRoot($this->tempDir),
            $this->sessionId,
        )->read();
        $this->assertNotSame('', $result, $this->collectDiagnostics($events));
        $this->assertStringContainsString('available: true', $result);
        $this->assertStringContainsString('src/CodingAgent/Runtime/Controller/HeadlessController.php', $result);
        $this->assertStringNotContainsString('available: false', $result);
        $this->assertStringNotContainsString('mode: disabled', $result);
        $this->assertStringNotContainsString('Fix CLI auth/daemon access', $result);
        $this->assertSame(
            JbcontextSessionModeEnum::Eligible,
            $statusAfter->mode,
            'status after tool must remain eligible: '.json_encode($statusAfter->toArray(), \JSON_THROW_ON_ERROR),
        );
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-replay-jbcontext-startup';
    }

    protected function modelConfig(): array
    {
        return [
            'input' => ['text'],
            'tool_calling' => true,
        ];
    }

    protected function extraSettingsYaml(): string
    {
        return <<<YAML
extensions:
    enabled:
        - Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension
        - Ineersa\\HatfieldExt\\Jbcontext\\JbcontextExtension
YAML;
    }

    /**
     * @return array<string, string>
     */
    protected function replayExtraEnv(): array
    {
        if ('' === $this->binDir) {
            return [];
        }

        $path = $this->binDir.\PATH_SEPARATOR.(getenv('PATH') ?: '/usr/bin:/bin');

        return [
            'PATH' => $path,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        return [
            [
                '$schema' => 'Synthetic controller replay — jbcontext code_search recovery',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Force one code_search tool call after eligibility recovery.',
                'model' => 'llama_cpp_test/test',
                'provider_id' => 'llama_cpp_test',
                'reasoning' => 'off',
                'stop_reason' => 'tool_call',
                'deltas' => [
                    [
                        'type' => 'tool_call_start',
                        'id' => self::TOOL_CALL_ID,
                        'name' => 'code_search',
                    ],
                    [
                        'type' => 'tool_input_delta',
                        'id' => self::TOOL_CALL_ID,
                        'name' => 'code_search',
                        'partial_json' => '{"text":'.json_encode(self::QUERY, \JSON_THROW_ON_ERROR).'}',
                    ],
                    [
                        'type' => 'tool_call_complete',
                        'tool_calls' => [
                            [
                                'id' => self::TOOL_CALL_ID,
                                'name' => 'code_search',
                                'arguments' => [
                                    'text' => self::QUERY,
                                ],
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'input_tokens' => 40,
                    'output_tokens' => 20,
                    'total_tokens' => 60,
                ],
                'expected_text' => '',
            ],
            [
                '$schema' => 'Synthetic controller replay — post-tool absorber',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Absorb the post-tool LLM turn so fixture exhaustion fails loudly.',
                'model' => 'llama_cpp_test/test',
                'provider_id' => 'llama_cpp_test',
                'reasoning' => 'off',
                'stop_reason' => 'stop',
                'deltas' => [
                    ['type' => 'text', 'content' => 'done'],
                ],
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 1,
                    'total_tokens' => 11,
                ],
                'expected_text' => 'done',
            ],
        ];
    }

    /**
     * @param callable(JbcontextSessionState): bool $predicate
     */
    private function waitForTerminalStatus(callable $predicate): JbcontextSessionState
    {
        $statusPath = JbcontextPaths::fromProjectRoot($this->tempDir)
            ->sessionStatusPath($this->sessionId);
        $deadline = microtime(true) + 8.0;
        $state = null;

        while (microtime(true) < $deadline) {
            $this->assertRunning('waiting for jbcontext terminal status');
            if (is_file($statusPath)) {
                $state = JbcontextStatusStore::forSession(
                    JbcontextPaths::fromProjectRoot($this->tempDir),
                    $this->sessionId,
                )->read();
                if ($predicate($state)) {
                    return $state;
                }
            }
            usleep(10_000);
        }

        $this->assertNotNull(
            $state,
            'Controller session-start must create session status before any turn. '
            .$this->collectDiagnostics([]),
        );
        $this->fail(
            'Timed out waiting for expected jbcontext status. last='
            .json_encode($state?->toArray(), \JSON_THROW_ON_ERROR)
            .' '.$this->collectDiagnostics([]),
        );
    }

    private function installStubJbcontext(): void
    {
        $this->binDir = $this->tempDir.'/.jbcontext-bin';
        $this->assertTrue(@mkdir($this->binDir, 0o777, true) || is_dir($this->binDir));
        $logPath = $this->binDir.'/calls.log';
        $binary = $this->binDir.'/jbcontext';
        $script = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
log_file="$(dirname "$0")/calls.log"
printf '%s\n' "$*" >>"$log_file"
cmd="${1:-}"
shift || true
case "$cmd" in
  status)
    cat <<'JSON'
{"type":"status_result","repositoryId":"github.com/ineersa/agent-core","indices":[{"indexAlias":{"name":"CodeBlocks"},"snapshots":[{"revision":"stub","branches":["main"]}]}],"message":"ok","storage":"local"}
JSON
    exit 0
    ;;
  search)
    cat <<'JSON'
{"type":"search_result","results":[{"result":{"scoredText":{"similarity":0.99},"sourcePosition":{"relativePath":"src/CodingAgent/Runtime/Controller/HeadlessController.php","startOffset":1,"endOffset":20},"indexItemType":"CHUNKS"},"content":"session owner lock prevents concurrent controllers","contentStartLine":497}],"message":"Search completed successfully","revision":"stub"}
JSON
    exit 0
    ;;
  index)
    # Incremental silent refresh after eligibility is allowed; first-index
    # creation is never required because status already reports a snapshot.
    exit 0
    ;;
  *)
    echo "unexpected jbcontext command: $cmd" >&2
    exit 2
    ;;
esac
BASH;
        file_put_contents($binary, $script);
        chmod($binary, 0o755);
        file_put_contents($logPath, '');
    }

    /**
     * @return list<string>
     */
    private function stubLogLines(): array
    {
        if ('' === $this->binDir) {
            return [];
        }
        $raw = (string) file_get_contents($this->binDir.'/calls.log');
        if ('' === trim($raw)) {
            return [];
        }

        return preg_split("/\n/", trim($raw)) ?: [];
    }
}
