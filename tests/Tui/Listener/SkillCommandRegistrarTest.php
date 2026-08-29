<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\SkillCatalogInterface;
use Ineersa\CodingAgent\Runtime\Contract\SkillCommand;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\DispatchRuntime;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Listener\SkillCommandRegistrar;
use Ineersa\Tui\Listener\SlashCommandCatalogRegistrar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SkillCommandRegistrarTest extends TestCase
{
    private SlashCommandCatalog $commandCatalog;
    private SkillCatalogInterface $catalog;

    protected function setUp(): void
    {
        $this->commandCatalog = new SlashCommandCatalog();
        $this->catalog = $this->createStub(SkillCatalogInterface::class);
    }

    #[Test]
    public function registersCommandPerSkillIncludingOnDemandOnly(): void
    {
        $this->catalog->method('allSkillCommands')->willReturn([
            new SkillCommand(name: 'skill:visible', description: 'Visible skill'),
            new SkillCommand(name: 'skill:hidden', description: 'On-demand only'),
        ]);

        $registrar = new SkillCommandRegistrar($this->catalog);
        $registrar->registerCatalog($this->commandCatalog);

        $this->assertTrue($this->commandCatalog->has('skill:visible'));
        $this->assertTrue($this->commandCatalog->has('skill:hidden'));

        $meta = $this->commandCatalog->getMetadata('skill:hidden');
        $this->assertNotNull($meta);
        $this->assertTrue($meta->acceptsArguments);
        $this->assertSame('On-demand only', $meta->description);
        $this->assertSame('/skill:hidden [args]', $meta->usage);
    }

    #[Test]
    public function handlerReturnsDispatchRuntimeWithOriginalText(): void
    {
        $this->catalog->method('allSkillCommands')->willReturn([
            new SkillCommand(name: 'skill:castor', description: 'Runs Castor'),
        ]);

        $registrar = new SkillCommandRegistrar($this->catalog);
        $registrar->registerCatalog($this->commandCatalog);

        $result = (new SlashCommandRegistry($this->commandCatalog))->execute(
            new SlashCommand('skill:castor', 'list tasks', '/skill:castor list tasks'),
        );
        $this->assertInstanceOf(DispatchRuntime::class, $result);
        $this->assertSame('/skill:castor list tasks', $result->payload);
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

        $this->catalog->method('allSkillCommands')->willReturn([
            new SkillCommand(name: 'skill:castor', description: 'Discovered castor'),
        ]);

        $registrar = new SkillCommandRegistrar($this->catalog);
        $registrar->registerCatalog($this->commandCatalog);

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
    public function implementsSlashCommandCatalogRegistrar(): void
    {
        $registrar = new SkillCommandRegistrar($this->catalog);
        $this->assertInstanceOf(SlashCommandCatalogRegistrar::class, $registrar);
        $this->assertSame(-100, SkillCommandRegistrar::getPriority());
    }
}
