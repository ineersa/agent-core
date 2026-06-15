<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * End-to-end proof that prompt-template expansion works through the
 * controller JSONL transport path.
 *
 * The chain: controller subprocess → JsonlProcessAgentSessionClient →
 * AgentCommand → InProcessAgentSessionClient::start() →
 * expandPromptTemplate() → expanded text visible in run.started payload.
 *
 * @group llm-real
 */
#[Group('llm-real')]
final class PromptTemplateControllerE2eTest extends ControllerE2eTestCase
{
    private string $templateMarker;

    /** @var list<string>|null Override controllerExtraArgs per test method. */
    private ?array $forcedExtraArgs = null;

    protected function tempDirPrefix(): string
    {
        return 'test-pt-ctrl';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->templateMarker = 'pt04e2e-'.bin2hex(random_bytes(4));

        // Create the prompt template in the isolated temp dir so both tests
        // share the same filesystem setup.  The first test exercises
        // auto-discovery expansion; the second passes --no-prompt-templates
        // and asserts the raw command passes through.
        $promptsDir = $this->tempDir.'/.hatfield/prompts';
        @mkdir($promptsDir, 0o777, true);
        file_put_contents(
            $promptsDir.'/review.md',
            "---\ndescription: Review code changes\n---\n\nReview: \$ARGUMENTS\n",
        );
    }

    /**
     * @test
     *
     * Proves the full controller-path expansion: /review <marker>
     * expands to "Review: <marker>" and the run.started user_messages
     * contain the expanded text — not the raw slash command.
     */
    public function testReviewTemplateExpandsViaControllerPath(): void
    {
        $expectedText = 'Review: '.$this->templateMarker;
        $rawCommand = '/review '.$this->templateMarker;

        $this->spawnController();
        $this->waitForEvent('runtime.ready', 5.0);

        $startCmdId = 'cmd_pt04_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => $rawCommand,
            ],
        ]);

        // Collect all events for a reasonable duration.  The controller first
        // emits a synthetic run.started from StartRunHandler, then the domain
        // event drain produces the enriched run.started with user_messages.
        // collectEvents returns when run.completed/run.failed/run.cancelled
        // arrives or the timeout expires.
        $events = $this->collectEvents(15.0);
        $byType = $this->indexByType($events);

        $this->assertStartRunAcked($events, $startCmdId);

        self::assertArrayHasKey(
            'run.started',
            $byType,
            'Expected run.started event with expanded template prompt.'."\n"
            .$this->collectDiagnostics($events),
        );

        // Find the run.started that carries user_messages (from RuntimeEventTranslator).
        // The synthetic one from StartRunHandler has only status, no user_messages.
        $richRunStarted = null;
        foreach ($byType['run.started'] as $rs) {
            if (!empty($rs['payload']['user_messages'] ?? null)) {
                $richRunStarted = $rs;
                break;
            }
        }
        self::assertNotNull(
            $richRunStarted,
            'No run.started event with user_messages found. '
            .'Available run.started events: '
            .json_encode(
                array_map(static fn (array $e): array => array_keys($e['payload'] ?? []), $byType['run.started']),
                \JSON_THROW_ON_ERROR,
            )."\n"
            .$this->collectDiagnostics($events),
        );

        $this->runId = (string) ($richRunStarted['runId'] ?? '');
        $userMessages = $richRunStarted['payload']['user_messages'];

        $userTexts = array_map(
            static fn (array $m): string => (string) ($m['text'] ?? ''),
            $userMessages,
        );

        // Proof: expanded text present.
        self::assertContains(
            $expectedText,
            $userTexts,
            sprintf(
                'run.started user_messages must contain expanded "%s". Actual: %s',
                $expectedText,
                json_encode($userTexts, \JSON_THROW_ON_ERROR),
            ),
        );

        // Proof: raw slash command absent.
        self::assertNotContains(
            $rawCommand,
            $userTexts,
            sprintf(
                'run.started user_messages must NOT contain raw "%s". Actual: %s',
                $rawCommand,
                json_encode($userTexts, \JSON_THROW_ON_ERROR),
            ),
        );

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertSessionArtifactsExist($sessionDir, $events);
    }

    /**
     * @test
     *
     * Proves --no-prompt-templates disables auto-discovery.  When
     * templates are disabled, /review <marker> passes through as
     * raw text — the expansion does NOT happen.
     */
    public function testNoPromptTemplatesDisablesAutoDiscovery(): void
    {
        $rawCommand = '/review '.$this->templateMarker;

        // Force --no-prompt-templates on the controller subprocess.
        $this->forcedExtraArgs = ['--tools-excluded=bash', '--no-prompt-templates'];

        $this->spawnController();
        $this->waitForEvent('runtime.ready', 5.0);

        $startCmdId = 'cmd_pt04b_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => $rawCommand,
            ],
        ]);

        // Same pattern: collectAll because the first run.started is synthetic.
        $events = $this->collectEvents(15.0);
        $byType = $this->indexByType($events);

        $this->assertStartRunAcked($events, $startCmdId);

        self::assertArrayHasKey(
            'run.started',
            $byType,
            'Expected run.started event.'."\n"
            .$this->collectDiagnostics($events),
        );

        // Find the rich run.started with user_messages.
        $richRunStarted = null;
        foreach ($byType['run.started'] as $rs) {
            if (!empty($rs['payload']['user_messages'] ?? null)) {
                $richRunStarted = $rs;
                break;
            }
        }
        self::assertNotNull(
            $richRunStarted,
            'No run.started event with user_messages found.'."\n"
            .$this->collectDiagnostics($events),
        );

        $this->runId = (string) ($richRunStarted['runId'] ?? '');
        $userMessages = $richRunStarted['payload']['user_messages'];

        $userTexts = array_map(
            static fn (array $m): string => (string) ($m['text'] ?? ''),
            $userMessages,
        );

        // Proof: raw slash command passes through verbatim when
        // templates are disabled.
        self::assertContains(
            $rawCommand,
            $userTexts,
            sprintf(
                'With --no-prompt-templates, raw "/review <marker>" '
                .'must appear verbatim. Actual: %s',
                json_encode($userTexts, \JSON_THROW_ON_ERROR),
            ),
        );

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertSessionArtifactsExist($sessionDir, $events);
    }

    // ── Overrides ────────────────────────────────────────────

    protected function controllerExtraArgs(): array
    {
        if (null !== $this->forcedExtraArgs) {
            return $this->forcedExtraArgs;
        }

        return ['--tools-excluded=bash'];
    }
}
