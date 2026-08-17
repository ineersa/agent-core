<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Extension;

use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Extension\TuiCommandRegistryAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TuiCommandRegistryAdapter — the bridge that registers
 * extension-provided slash commands into the process-scoped SlashCommandCatalog.
 */
final class TuiCommandRegistryAdapterTest extends TestCase
{
    public function testRegistersCommandInSlashCommandCatalog(): void
    {
        $catalog = new SlashCommandCatalog();
        $adapter = new TuiCommandRegistryAdapter($catalog);

        $definition = new CommandDefinitionDTO(
            name: 'tasks',
            aliases: ['t'],
            description: 'List tasks',
            usage: '/tasks',
            acceptsArguments: false,
        );

        $handler = new readonly class implements ExtensionCommandHandlerInterface {
            public function handle(string $args, CommandContextInterface $context): void
            {
            }
        };

        $adapter->register($definition, $handler);

        $this->assertTrue($catalog->has('tasks'));
        $this->assertTrue($catalog->has('t'));

        $meta = $catalog->getMetadata('tasks');
        $this->assertNotNull($meta);
        $this->assertSame('tasks', $meta->name);
        $this->assertSame(['t'], $meta->aliases);
        $this->assertSame('List tasks', $meta->description);
        $this->assertFalse($meta->acceptsArguments);
    }

    public function testHandlerIsInvokedWithCorrectArgs(): void
    {
        $catalog = new SlashCommandCatalog();
        $adapter = new TuiCommandRegistryAdapter($catalog);

        $handler = new class implements ExtensionCommandHandlerInterface {
            public ?string $capturedArgs = null;

            public function handle(string $args, CommandContextInterface $context): void
            {
                $this->capturedArgs = $args;
            }
        };

        $adapter->register(
            new CommandDefinitionDTO(name: 'tasks', acceptsArguments: true),
            $handler,
        );

        $registry = new SlashCommandRegistry($catalog);
        $result = $registry->execute(new SlashCommand('tasks', 'TODO', '/tasks TODO'));
        $this->assertInstanceOf(NoOp::class, $result);

        $this->assertSame('TODO', $handler->capturedArgs);
    }

    public function testDynamicRegistrationIsImmediatelyVisibleToExistingSessionRegistry(): void
    {
        $catalog = new SlashCommandCatalog();
        // The session-local registry exists BEFORE the extension registers.
        $registry = new SlashCommandRegistry($catalog);

        $adapter = new TuiCommandRegistryAdapter($catalog);
        $handler = new class implements ExtensionCommandHandlerInterface {
            public ?string $capturedArgs = null;

            public function handle(string $args, CommandContextInterface $context): void
            {
                $this->capturedArgs = $args;
            }
        };
        $adapter->register(
            new CommandDefinitionDTO(name: 'tasks', aliases: ['t'], description: 'List tasks', acceptsArguments: true),
            $handler,
        );

        // Metadata and alias resolve immediately through the shared catalog.
        $this->assertTrue($catalog->has('tasks'));
        $this->assertTrue($catalog->has('t'));
        $this->assertSame('tasks', $catalog->resolveName('t'));

        // The pre-existing session registry executes the new command and its
        // alias immediately — dynamic extension registrations stay visible
        // during a running session.
        $result = $registry->execute(new SlashCommand('tasks', 'TODO', '/tasks TODO'));
        $this->assertInstanceOf(NoOp::class, $result);
        $this->assertSame('TODO', $handler->capturedArgs);

        $result = $registry->execute(new SlashCommand('t', '', '/t'));
        $this->assertInstanceOf(NoOp::class, $result);
        $this->assertSame('', $handler->capturedArgs);
    }

    public function testNotifySurfacesMessagesAsTranscript(): void
    {
        $catalog = new SlashCommandCatalog();
        $adapter = new TuiCommandRegistryAdapter($catalog);

        $handler = new readonly class implements ExtensionCommandHandlerInterface {
            public function handle(string $args, CommandContextInterface $context): void
            {
                $context->notify('Task board at /path/to/tasks', 'info');
                $context->notify('3 tasks in TODO', 'success');
            }
        };

        $adapter->register(
            new CommandDefinitionDTO(name: 'summary'),
            $handler,
        );

        $registry = new SlashCommandRegistry($catalog);
        $result = $registry->execute(new SlashCommand('summary', '', '/summary'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Task board at /path/to/tasks', $result->text);
        $this->assertStringContainsString('3 tasks in TODO', $result->text);
    }

    public function testMultipleCommandsRegistered(): void
    {
        $catalog = new SlashCommandCatalog();
        $adapter = new TuiCommandRegistryAdapter($catalog);

        $adapter->register(
            new CommandDefinitionDTO(name: 'tasks'),
            new readonly class implements ExtensionCommandHandlerInterface {
                public function handle(string $args, CommandContextInterface $context): void
                {
                }
            },
        );

        $adapter->register(
            new CommandDefinitionDTO(name: 'summary'),
            new readonly class implements ExtensionCommandHandlerInterface {
                public function handle(string $args, CommandContextInterface $context): void
                {
                }
            },
        );

        $this->assertTrue($catalog->has('tasks'));
        $this->assertTrue($catalog->has('summary'));
    }

    public function testDuplicatedCommandNameThrows(): void
    {
        $catalog = new SlashCommandCatalog();
        $adapter = new TuiCommandRegistryAdapter($catalog);

        $adapter->register(
            new CommandDefinitionDTO(name: 'dup'),
            new readonly class implements ExtensionCommandHandlerInterface {
                public function handle(string $args, CommandContextInterface $context): void
                {
                }
            },
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');

        $adapter->register(
            new CommandDefinitionDTO(name: 'dup'),
            new readonly class implements ExtensionCommandHandlerInterface {
                public function handle(string $args, CommandContextInterface $context): void
                {
                }
            },
        );
    }

    public function testAcceptsArgumentsRespectedBySlashCommandRegistry(): void
    {
        $catalog = new SlashCommandCatalog();
        $adapter = new TuiCommandRegistryAdapter($catalog);

        $handler = new class implements ExtensionCommandHandlerInterface {
            public ?string $capturedArgs = null;

            public function handle(string $args, CommandContextInterface $context): void
            {
                $this->capturedArgs = $args;
            }
        };

        $adapter->register(
            new CommandDefinitionDTO(name: 'noarg', acceptsArguments: false),
            $handler,
        );

        // /noarg extra-stuff → registry strips "extra-stuff" because acceptArguments=false
        $registry = new SlashCommandRegistry($catalog);
        $result = $registry->execute(new SlashCommand('noarg', 'extra-stuff', '/noarg extra-stuff'));
        $this->assertSame('', $handler->capturedArgs);
    }

    public function testNotifyErrorLevelProducesErrorRole(): void
    {
        $catalog = new SlashCommandCatalog();
        $adapter = new TuiCommandRegistryAdapter($catalog);

        $handler = new readonly class implements ExtensionCommandHandlerInterface {
            public function handle(string $args, CommandContextInterface $context): void
            {
                $context->notify('Setup error: permission denied', 'error');
            }
        };

        $adapter->register(
            new CommandDefinitionDTO(name: 'tasks'),
            $handler,
        );

        $registry = new SlashCommandRegistry($catalog);
        $result = $registry->execute(new SlashCommand('tasks', '', '/tasks'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Setup error: permission denied', $result->text);
        $this->assertSame('error', $result->role);
    }

    public function testNotifyWarningLevelProducesAccentStyle(): void
    {
        $catalog = new SlashCommandCatalog();
        $adapter = new TuiCommandRegistryAdapter($catalog);

        $handler = new readonly class implements ExtensionCommandHandlerInterface {
            public function handle(string $args, CommandContextInterface $context): void
            {
                $context->notify('Watch out', 'warning');
            }
        };

        $adapter->register(
            new CommandDefinitionDTO(name: 'tasks'),
            $handler,
        );

        $registry = new SlashCommandRegistry($catalog);
        $result = $registry->execute(new SlashCommand('tasks', '', '/tasks'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('accent', $result->style);
    }

    public function testErrorLevelOverridesWarningLevel(): void
    {
        // When mixed levels are notified, the highest severity wins.
        $catalog = new SlashCommandCatalog();
        $adapter = new TuiCommandRegistryAdapter($catalog);

        $handler = new readonly class implements ExtensionCommandHandlerInterface {
            public function handle(string $args, CommandContextInterface $context): void
            {
                $context->notify('Task board ready', 'info');
                $context->notify('Watch out', 'warning');
                $context->notify('Fatal: unreachable', 'error');
            }
        };

        $adapter->register(
            new CommandDefinitionDTO(name: 'tasks'),
            $handler,
        );

        $registry = new SlashCommandRegistry($catalog);
        $result = $registry->execute(new SlashCommand('tasks', '', '/tasks'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('error', $result->role);
        $this->assertSame('error', $result->style);
    }

    public function testInfoNotifyProducesMarkdownStyle(): void
    {
        $catalog = new SlashCommandCatalog();
        $adapter = new TuiCommandRegistryAdapter($catalog);

        $handler = new readonly class implements ExtensionCommandHandlerInterface {
            public function handle(string $args, CommandContextInterface $context): void
            {
                $context->notify("## Hello\n- **item**", 'info');
            }
        };

        $adapter->register(
            new CommandDefinitionDTO(name: 'mdcmd'),
            $handler,
        );

        $registry = new SlashCommandRegistry($catalog);
        $result = $registry->execute(new SlashCommand('mdcmd', '', '/mdcmd'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('system', $result->role);
        $this->assertSame('markdown', $result->style);
        $this->assertStringContainsString('## Hello', $result->text);
    }
}
