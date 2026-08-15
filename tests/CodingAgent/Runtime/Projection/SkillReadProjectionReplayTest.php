<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Projection;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\SkillReadProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Focused replay-projection regression: assistant.message_completed reconstructs skill_name.
 *
 * Test thesis: resume/replay path attaches the same skill_name metadata used by
 * live tool_call.arguments_completed annotation, so skill cards survive resume.
 */
final class SkillReadProjectionReplayTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('skill-read-replay');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    #[Test]
    public function testMessageCompletedReplayAnnotatesSkillNameOnReconstructedToolCall(): void
    {
        $skillDir = $this->tmpDir.'/.agents/skills/testing';
        mkdir($skillDir, 0777, true);
        $skillFile = $skillDir.'/SKILL.md';
        file_put_contents($skillFile, "---\nname: testing\ndescription: Testing skill\n---\n\nBody\n");

        $unrelated = $this->tmpDir.'/other/SKILL.md';
        mkdir(\dirname($unrelated), 0777, true);
        file_put_contents($unrelated, "---\nname: other\ndescription: other\n---\n");

        $homeDir = $this->tmpDir.'/home';
        mkdir($homeDir, 0777, true);
        $discovery = new SkillDiscovery(
            config: new SkillsConfig(),
            pathResolver: new SettingsPathResolver($this->tmpDir, $homeDir),
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'test'),
                logging: new LoggingConfig(),
                cwd: $this->tmpDir,
            ),
            extractor: new MarkdownFrontmatterExtractor(),
            resources: new AppResourceLocator($this->tmpDir),
            filesystem: new Filesystem(),
        );

        $dispatcher = new EventDispatcher();
        $state = new TranscriptProjectionState();
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $dispatcher->addSubscriber(new SkillReadProjectionSubscriber($discovery));
        $projector = new TranscriptProjector($dispatcher, $state);

        $projector->accept(new RuntimeEvent(
            type: 'assistant.message_completed',
            runId: 'replay-run',
            seq: 1,
            payload: [
                'message_id' => 'msg-1',
                'text' => 'Loading skill',
                'tool_calls' => [
                    [
                        'id' => 'call-skill-replay',
                        'name' => 'read',
                        'arguments' => [
                            'path' => $skillFile,
                            'offset' => 1,
                            'limit' => 400,
                        ],
                    ],
                    [
                        'id' => 'call-ordinary-replay',
                        'name' => 'read',
                        'arguments' => [
                            'path' => $unrelated,
                        ],
                    ],
                ],
            ],
        ));

        $blocks = $projector->blocks();
        $byCallId = [];
        foreach ($blocks as $block) {
            if (TranscriptBlockKindEnum::ToolCall !== $block->kind) {
                continue;
            }
            $callId = $block->meta['tool_call_id'] ?? null;
            if (\is_string($callId)) {
                $byCallId[$callId] = $block;
            }
        }

        $this->assertArrayHasKey('call-skill-replay', $byCallId);
        $this->assertSame('testing', $byCallId['call-skill-replay']->meta['skill_name'] ?? null);
        $this->assertArrayHasKey('call-ordinary-replay', $byCallId);
        $this->assertArrayNotHasKey('skill_name', $byCallId['call-ordinary-replay']->meta);
    }
}
