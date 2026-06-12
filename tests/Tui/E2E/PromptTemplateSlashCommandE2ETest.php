<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke test for prompt-template slash command dispatch.
 *
 * Starts the agent TUI with a .hatfield/prompts/review.md template,
 * types /review <unique-marker>, submits it, and asserts the expanded
 * template text appears in the tmux history — proving the full chain:
 * registrar → DispatchRuntime → SubmitListener → runtime expansion.
 *
 * @group tui-e2e
 */
#[Group('tui-e2e')]
final class PromptTemplateSlashCommandE2ETest extends TestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;
    private string $snapshotDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            self::markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        // TmuxHarness destructor kills all sessions.
    }

    /**
     * @test
     *
     * Types /review <marker> and verifies the expanded template
     * text appears in the tmux pane history. Uses a unique marker
     * to avoid false positives from static help text.
     */
    public function testReviewTemplateSlashCommandDispatchesAndExpands(): void
    {
        $marker = 'pt-03-e2e-'.bin2hex(random_bytes(4));
        $expectedText = 'Review: '.$marker;

        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'hatfield-pt03',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        // Wait for agent boot (logo █ visible).
        $this->tmux->waitForCaptureContains($pane, '█', 10.0);

        // Type /review <marker> and submit
        $this->tmux->sendLiteral($pane, '/review '.$marker);
        $this->tmux->sendKey($pane, 'Enter');

        // Wait for the expanded text to appear in the pane history.
        // The expansion happens at the in-process runtime boundary (PT-02),
        // and the expanded prompt is projected by the transcript projector.
        $this->tmux->waitForCallback(
            $pane,
            static function (string $capture) use ($expectedText): bool {
                return str_contains($capture, $expectedText);
            },
            timeout: 10.0,
            message: sprintf(
                'Expanded template text "%s" never appeared in tmux output.',
                $expectedText,
            ),
            history: 2000,
        );

        // Capture full history to confirm the expanded text.
        $capture = $this->tmux->capturePlainWithHistory($pane, 2000);

        self::assertStringContainsString(
            $expectedText,
            $capture,
            sprintf(
                'Expected expanded template "%s" to be visible in tmux pane.',
                $expectedText,
            ),
        );

        // Optionally wait for assistant response or error block to confirm full pipeline.
        try {
            $this->tmux->waitForCallback(
                $pane,
                static function (string $capture): bool {
                    return str_contains($capture, '◇') || str_contains($capture, '✕');
                },
                timeout: 15.0,
                message: 'No assistant block or error block appeared after template expansion.',
                history: 2000,
            );
        } catch (\RuntimeException $e) {
            // This is non-fatal — the core assertion (expanded text visible) already passed.
            // If the LLM doesn't respond in time, the test still proves template dispatch works.
        }

        $this->saveAnsiSnapshot($pane, 'review-template-expanded');
    }

    // ── Helpers ───────────────────────────────────────────────

    private function agentCommand(): string
    {
        [$php, $script] = AgentTestExecutable::command();

        return \sprintf(
            'APP_ENV=dev HOME=%s %s %s agent --model=llama_cpp_test/test 2>&1',
            \escapeshellarg($this->testProjectDir.'/home'),
            \escapeshellarg($php),
            \escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = \sprintf(
            '%s/var/tmp/tui-e2e-pt03-%s',
            $this->projectRoot,
            \bin2hex(\random_bytes(6)),
        );
        @\mkdir($dir.'/.hatfield/prompts', 0o777, true);
        @\mkdir($dir.'/home/.hatfield', 0o777, true);

        // Write a prompt template: review.md with frontmatter
        $templateContent = "---\ndescription: Review code changes\n---\n\nReview: \$ARGUMENTS\n";
        \file_put_contents($dir.'/.hatfield/prompts/review.md', $templateContent);

        $settings = [
            'ai' => [
                'default_model' => 'llama_cpp_test/test',
                'providers' => [
                    'llama_cpp_test' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'http://192.168.2.38:9052/v1',
                        'api' => 'openai-completions',
                        'api_key' => 'dummy',
                        'completions_path' => '/chat/completions',
                        'supports_completions' => true,
                        'supports_embeddings' => false,
                        'models' => [
                            'test' => [
                                'name' => 'test',
                                'context_window' => 32768,
                                'max_tokens' => 32768,
                                'input' => ['text', 'image'],
                                'tool_calling' => true,
                                'cost' => ['input' => 0, 'output' => 0],
                            ],
                        ],
                    ],
                ],
            ],
            'extensions' => [
                'enabled' => [
                    'Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension',
                ],
                'settings' => [
                    'safe_guard' => [
                        'tool_names' => [
                            'bash' => 'bash',
                            'write' => 'write',
                            'edit' => 'edit',
                            'read' => 'read',
                        ],
                        'allow_command_patterns' => ['^ls\b', '^printf\b', '^echo\b'],
                        'allow_write_outside_cwd' => [],
                        'protected_read_patterns' => [],
                        'dangerous_command_patterns' => [],
                    ],
                ],
            ],
        ];

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        \file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        \file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        \file_put_contents($path, $ansi);
    }
}
