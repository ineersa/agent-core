<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\DispatchRuntime;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Listener\ExportCommandRegistrar;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic /export slash-command proof without tmux.
 *
 * Test thesis: SubmissionRouter routes /export through the production
 * slash registry and ExportCommandHandler, renders confirmation on the
 * virtual screen, and writes default-path HTML in the isolated project cwd.
 */
final class TuiExportCommandVirtualTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private const string SESSION_ID = 'virtual-export-session';

    private string $projectDir;

    private string $previousCwd;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('tui-export-virtual');
        $this->previousCwd = getcwd() ?: '';
        chdir($this->projectDir);
        $this->writeEventsJsonl(self::SESSION_ID);
    }

    protected function tearDown(): void
    {
        if ('' !== $this->previousCwd) {
            chdir($this->previousCwd);
        }
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    #[Test]
    public function testExportSlashCommandRoutesLocallyRendersConfirmationAndWritesHtml(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $state = new TuiSessionState(self::SESSION_ID);
        $sessionStore = $this->createSessionStoreForProject();

        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->withSessionStore($sessionStore)
            ->build();

        $registry = new SlashCommandRegistry();
        (new ExportCommandRegistrar($registry))->register($context);

        $router = new SubmissionRouter(new CommandParser(), $registry);
        $result = $router->route('/export');

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertNotInstanceOf(DispatchRuntime::class, $result);
        $this->assertStringContainsString('Session exported to:', $result->text);

        $expectedPath = $this->projectDir.'/hatfield-session-'.self::SESSION_ID.'.html';
        $this->assertStringContainsString($expectedPath, $result->text);
        $this->assertFileExists($expectedPath);

        $html = file_get_contents($expectedPath);
        $this->assertIsString($html);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Hatfield Session', $html);
        $this->assertStringNotContainsString('<script>', $html);

        $factory = new TranscriptBlockFactory();
        $block = $factory->system(
            runId: self::SESSION_ID,
            text: $result->text,
            seq: 1,
            style: $result->style,
        );
        $harness->screen()->setTranscriptBlocks([$block]);

        $screen = $harness->plainScreenText();
        $this->assertStringContainsString('Session exported to:', $screen);
        $this->assertStringContainsString('hatfield-session-'.self::SESSION_ID.'.html', $screen);
    }

    private function createSessionStoreForProject(): HatfieldSessionStore
    {
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
            sessions: new SessionsConfig(path: '.hatfield/sessions'),
        );

        return new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(EntityManagerInterface::class),
        );
    }

    private function writeEventsJsonl(string $sessionId): void
    {
        $sessionDir = $this->projectDir.'/.hatfield/sessions/'.$sessionId;
        if (!is_dir($sessionDir) && !mkdir($sessionDir, 0777, true) && !is_dir($sessionDir)) {
            throw new \RuntimeException('Failed to create session dir: '.$sessionDir);
        }

        $event = [
            'schema_version' => '1.0',
            'run_id' => $sessionId,
            'seq' => 1,
            'turn_no' => 1,
            'type' => 'run_started',
            'payload' => [
                'step_id' => 's1',
                'user_messages' => [['role' => 'user', 'content' => 'Export me']],
            ],
            'ts' => '2026-01-01T00:00:00+00:00',
        ];

        file_put_contents(
            $sessionDir.'/events.jsonl',
            json_encode($event, \JSON_THROW_ON_ERROR)."\n",
        );
    }
}
