<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Skills\SkillContextRenderer;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\DispatchRuntime;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Listener\SkillCommandRegistrar;
use Ineersa\Tui\Listener\SlashCommandCatalogRegistrar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SkillCommandRegistrarTest extends TestCase
{
    private string $tmpDir;
    private SlashCommandCatalog $commandCatalog;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('skill_command_registrar');
        mkdir($this->tmpDir.'/home', 0777, true);
        $this->commandCatalog = new SlashCommandCatalog();
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    #[Test]
    public function registersCommandPerSkillIncludingOnDemandOnly(): void
    {
        $this->createSkill('skills/visible', 'visible', 'Visible skill');
        $this->createSkill('skills/hidden', 'hidden', 'On-demand only', onDemandOnly: true);

        $this->createRegistrar()->registerCatalog($this->commandCatalog);

        $this->assertTrue($this->commandCatalog->has('skill:visible'));
        $this->assertTrue($this->commandCatalog->has('skill:hidden'));

        $meta = $this->commandCatalog->getMetadata('skill:hidden');
        $this->assertNotNull($meta);
        $this->assertFalse($meta->acceptsArguments);
        $this->assertSame('On-demand only', $meta->description);
        $this->assertSame('/skill:hidden', $meta->usage);
    }

    #[Test]
    public function handlerReturnsDispatchRuntime(): void
    {
        $this->createSkill('skills/castor', 'castor', 'Runs Castor');
        $this->createRegistrar()->registerCatalog($this->commandCatalog);

        $result = (new SlashCommandRegistry($this->commandCatalog))->execute(
            new SlashCommand('skill:castor', '', '/skill:castor'),
        );
        $this->assertInstanceOf(DispatchRuntime::class, $result);
        $this->assertStringContainsString('<skill name="castor"', $result->payload);
        $this->assertStringContainsString('Body', $result->payload);
    }

    #[Test]
    public function mixedCaseInvocationDispatchesNormalizedSkillCommand(): void
    {
        $this->createSkill('skills/datadog', 'DataDog-Logs', 'Datadog logs');
        $this->createRegistrar()->registerCatalog($this->commandCatalog);

        $command = (new CommandParser())->parse('/skill:DataDog-Logs');
        $this->assertInstanceOf(SlashCommand::class, $command);

        $result = (new SlashCommandRegistry($this->commandCatalog))->execute($command);
        $this->assertInstanceOf(DispatchRuntime::class, $result);
        $this->assertStringContainsString('<skill name="DataDog-Logs"', $result->payload);
        $this->assertStringContainsString('Body', $result->payload);
    }

    #[Test]
    public function caseFoldedCollisionKeepsFirstDiscoveredSkill(): void
    {
        $first = $this->createSkill('first', 'Foo', 'First skill');
        $second = $this->createSkill('second', 'foo', 'Second skill');

        $this->createRegistrar([$first, $second])->registerCatalog($this->commandCatalog);

        $meta = $this->commandCatalog->getMetadata('skill:foo');
        $this->assertNotNull($meta);
        $this->assertSame('First skill', $meta->description);
    }

    #[Test]
    public function skipsWhenRealCommandAlreadyRegistered(): void
    {
        $realHandler = new class implements SlashCommandHandler {
            public function handle(SlashCommand $command): DispatchRuntime
            {
                return new DispatchRuntime('from-real-handler');
            }
        };

        $this->commandCatalog->register(
            new CommandMetadata(
                name: 'skill:castor',
                description: 'Real skill command',
                usage: '/skill:castor',
            ),
            $realHandler,
        );

        $this->createSkill('skills/castor', 'castor', 'Discovered castor');
        $this->createRegistrar()->registerCatalog($this->commandCatalog);

        $result = (new SlashCommandRegistry($this->commandCatalog))->execute(
            new SlashCommand('skill:castor', '', '/skill:castor'),
        );
        $this->assertInstanceOf(DispatchRuntime::class, $result);
        $this->assertSame('from-real-handler', $result->payload);

        $meta = $this->commandCatalog->getMetadata('skill:castor');
        $this->assertNotNull($meta);
        $this->assertSame('Real skill command', $meta->description);
    }

    #[Test]
