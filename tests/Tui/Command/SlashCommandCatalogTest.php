<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Command;

use Ineersa\Tui\Command\BuiltInSlashCommands;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(SlashCommandCatalog::class)]
#[CoversClass(CommandMetadata::class)]
#[CoversClass(BuiltInSlashCommands::class)]
final class SlashCommandCatalogTest extends TestCase
{
    private SlashCommandCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new SlashCommandCatalog();
    }

    // ─── Registration ────────────────────────────────────────────────

    #[Test]
    public function registersAndLooksUpByCanonicalName(): void
    {
        $handler = $this->createMockHandler();
        $metadata = new CommandMetadata(name: 'foo', description: 'Does foo things');

        $this->catalog->register($metadata, $handler);

        $this->assertTrue($this->catalog->has('foo'));
        $this->assertSame($metadata, $this->catalog->getMetadata('foo'));
        $this->assertSame($handler, $this->catalog->defaultHandler('foo'));
    }

    #[Test]
    public function looksUpByAlias(): void
    {
        $handler = $this->createMockHandler();
        $metadata = new CommandMetadata(name: 'foo', aliases: ['f', 'bar']);

        $this->catalog->register($metadata, $handler);

        $this->assertTrue($this->catalog->has('f'));
        $this->assertTrue($this->catalog->has('bar'));
        $this->assertSame($metadata, $this->catalog->getMetadata('f'));
        $this->assertSame($metadata, $this->catalog->getMetadata('bar'));
    }

    #[Test]
    public function hasReturnsFalseForUnregisteredName(): void
    {
        $this->assertFalse($this->catalog->has('nonexistent'));
    }

    #[Test]
    public function getMetadataReturnsNullForUnregisteredName(): void
    {
        $this->assertNull($this->catalog->getMetadata('nonexistent'));
    }

    #[Test]
    public function defaultHandlerReturnsNullForMetadataOnlyCommands(): void
    {
        $this->assertNull($this->catalog->defaultHandler('help'));
    }

    #[Test]
    public function throwsWhenRegisteringDuplicateName(): void
    {
        $this->catalog->register(
            new CommandMetadata(name: 'dup'),
            $this->createMockHandler(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Command 'dup' is already registered.");

        $this->catalog->register(
            new CommandMetadata(name: 'dup'),
            $this->createMockHandler(),
        );
    }

    #[Test]
    public function throwsWhenAliasConflictsWithExistingAlias(): void
    {
        $this->catalog->register(
            new CommandMetadata(name: 'first', aliases: ['shared']),
            $this->createMockHandler(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Alias 'shared' is already registered for command 'first'.");

        $this->catalog->register(
            new CommandMetadata(name: 'second', aliases: ['shared']),
            $this->createMockHandler(),
        );
    }

    #[Test]
    public function throwsWhenAliasConflictsWithExistingCommandName(): void
    {
        $this->catalog->register(
            new CommandMetadata(name: 'existing'),
            $this->createMockHandler(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Alias 'existing' conflicts with registered command name.");

        $this->catalog->register(
            new CommandMetadata(name: 'other', aliases: ['existing']),
            $this->createMockHandler(),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function builtInAliasNames(): iterable
    {
        yield 'exit alias q' => ['q', 'exit'];
        yield 'help alias h' => ['h', 'help'];
        yield 'help alias ?' => ['?', 'help'];
    }

    #[Test]
    #[DataProvider('builtInAliasNames')]
    public function throwsWhenCommandNameConflictsWithExistingAlias(string $name, string $owner): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Command '{$name}' conflicts with alias of registered command '{$owner}'.");

        $this->catalog->register(new CommandMetadata(name: $name), $this->createMockHandler());
    }

    // ─── Metadata access ─────────────────────────────────────────────

    #[Test]
    public function allMetadataReturnsSortedList(): void
    {
        // Register out of order
        $this->catalog->register(
            new CommandMetadata(name: 'zebra'),
            $this->createMockHandler(),
        );
        $this->catalog->register(
            new CommandMetadata(name: 'alpha'),
            $this->createMockHandler(),
        );

        $all = $this->catalog->allMetadata();
        $names = array_map(static fn (CommandMetadata $m) => $m->name, $all);

        // Should be sorted alphabetically, built-ins included
        $sorted = $names;
        sort($sorted, \SORT_STRING);
        $this->assertSame($sorted, $names);
        $this->assertSame('alpha', $names[2]);
        $this->assertSame('zebra', $names[array_key_last($names)]);
    }

    // ─── Built-in command metadata ───────────────────────────────────

    #[Test]
    public function builtInHelpHasCorrectMetadata(): void
    {
        $meta = $this->catalog->getMetadata('help');

        $this->assertNotNull($meta);
        $this->assertSame('help', $meta->name);
        $this->assertContains('h', $meta->aliases);
        $this->assertContains('?', $meta->aliases);
        $this->assertNotEmpty($meta->description);
        $this->assertNotEmpty($meta->usage);
    }

    #[Test]
    public function builtInClearHasCorrectMetadata(): void
    {
        $meta = $this->catalog->getMetadata('clear');

        $this->assertNotNull($meta);
        $this->assertSame('clear', $meta->name);
        $this->assertContains('cls', $meta->aliases);
        $this->assertNotEmpty($meta->description);
    }

    #[Test]
    public function builtInExitHasCorrectMetadata(): void
    {
        $meta = $this->catalog->getMetadata('exit');

        $this->assertNotNull($meta);
        $this->assertSame('exit', $meta->name);
        $this->assertContains('quit', $meta->aliases);
        $this->assertContains('q', $meta->aliases);
        $this->assertNotEmpty($meta->description);
    }

    #[Test]
    #[TestWith(['r', 'resume'])]
    #[TestWith(['resume', 'resume'])]
    #[TestWith(['cp', 'copy'])]
    #[TestWith(['m', 'model'])]
    #[TestWith(['main', 'agents-main'])]
    public function seedsSessionBoundBuiltInsAtConstruction(string $name, string $canonical): void
    {
        $this->assertSame($canonical, $this->catalog->resolveName($name));
        $this->assertNull($this->catalog->defaultHandler($canonical), 'Handlers are bound per session');
    }

    #[Test]
    public function rejectsRegisteringBuiltInAliasAfterConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Command 'r' conflicts with alias of registered command 'resume'.");

        $this->catalog->register(new CommandMetadata(name: 'r'), $this->createMockHandler());
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function createMockHandler(): SlashCommandHandler
    {
        return new NoOpTestHandler();
    }
}
