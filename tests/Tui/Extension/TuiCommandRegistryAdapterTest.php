<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Extension;

use Ineersa\Hatfield\ExtensionApi\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\ExtensionCommandHandlerInterface;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Extension\TuiCommandRegistryAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TuiCommandRegistryAdapter — the bridge that registers
 * extension-provided slash commands into the SlashCommandRegistry.
 */
final class TuiCommandRegistryAdapterTest extends TestCase
{
    public function testRegistersCommandInSlashCommandRegistry(): void
    {
        $slashRegistry = new SlashCommandRegistry();
        $adapter = new TuiCommandRegistryAdapter($slashRegistry);

        $definition = new CommandDefinitionDTO(
            name: 'tasks',
            aliases: ['t'],
            description: 'List tasks',
            usage: '/tasks',
            acceptsArguments: false,
        );

        $handler = new readonly class implements ExtensionCommandHandlerInterface {
            public function handle(string $args, CommandContextInterface $context): void {}
        };

        $adapter->register($definition, $handler);

        $this->assertTrue($slashRegistry->has('tasks'));
        $this->assertTrue($slashRegistry->has('t'));

        $meta = $slashRegistry->getMetadata('tasks');
        $this->assertNotNull($meta);
        $this->assertSame('tasks', $meta->name);
        $this->assertSame(['t'], $meta->aliases);
        $this->assertSame('List tasks', $meta->description);
        $this->assertFalse($meta->acceptsArguments);
    }

    public function testHandlerIsInvokedWithCorrectArgs(): void
    {
        $slashRegistry = new SlashCommandRegistry();
        $adapter = new TuiCommandRegistryAdapter($slashRegistry);

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

        $result = $slashRegistry->execute(new SlashCommand('tasks', 'TODO', '/tasks TODO'));
        $this->assertInstanceOf(TranscriptMessage::class, $result);

        $this->assertSame('TODO', $handler->capturedArgs);
    }

    public function testNotifySurfacesMessagesAsTranscript(): void
    {
        $slashRegistry = new SlashCommandRegistry();
        $adapter = new TuiCommandRegistryAdapter($slashRegistry);

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

        $result = $slashRegistry->execute(new SlashCommand('summary', '', '/summary'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Task board at /path/to/tasks', $result->text);
        $this->assertStringContainsString('3 tasks in TODO', $result->text);
    }

    public function testMultipleCommandsRegistered(): void
    {
        $slashRegistry = new SlashCommandRegistry();
        $adapter = new TuiCommandRegistryAdapter($slashRegistry);

        $adapter->register(
            new CommandDefinitionDTO(name: 'tasks'),
            new readonly class implements ExtensionCommandHandlerInterface {
                public function handle(string $args, CommandContextInterface $context): void {}
            },
        );

        $adapter->register(
            new CommandDefinitionDTO(name: 'summary'),
            new readonly class implements ExtensionCommandHandlerInterface {
                public function handle(string $args, CommandContextInterface $context): void {}
            },
        );

        $this->assertTrue($slashRegistry->has('tasks'));
        $this->assertTrue($slashRegistry->has('summary'));
    }

    public function testDuplicatedCommandNameThrows(): void
    {
        $slashRegistry = new SlashCommandRegistry();
        $adapter = new TuiCommandRegistryAdapter($slashRegistry);

        $adapter->register(
            new CommandDefinitionDTO(name: 'dup'),
            new readonly class implements ExtensionCommandHandlerInterface {
                public function handle(string $args, CommandContextInterface $context): void {}
            },
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');

        $adapter->register(
            new CommandDefinitionDTO(name: 'dup'),
            new readonly class implements ExtensionCommandHandlerInterface {
                public function handle(string $args, CommandContextInterface $context): void {}
            },
        );
    }

    public function testAcceptsArgumentsRespectedBySlashCommandRegistry(): void
    {
        $slashRegistry = new SlashCommandRegistry();
        $adapter = new TuiCommandRegistryAdapter($slashRegistry);

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
        $slashRegistry->execute(new SlashCommand('noarg', 'extra-stuff', '/noarg extra-stuff'));
        $this->assertSame('', $handler->capturedArgs);
    }
}
