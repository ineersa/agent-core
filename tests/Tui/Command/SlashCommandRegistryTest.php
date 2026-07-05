<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Command;

use Ineersa\Tui\Command\ClearTranscript;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\ExitApplication;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\TranscriptMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SlashCommandRegistry::class)]
#[CoversClass(CommandMetadata::class)]
final class SlashCommandRegistryTest extends TestCase
{
    private SlashCommandRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new SlashCommandRegistry();
    }

    // ─── Registration ────────────────────────────────────────────────

    #[Test]
    public function registersAndLooksUpByCanonicalName(): void
    {
        $handler = $this->createMockHandler();
        $metadata = new CommandMetadata(name: 'foo', description: 'Does foo things');

        $this->registry->register($metadata, $handler);

        $this->assertTrue($this->registry->has('foo'));
        $this->assertSame($metadata, $this->registry->getMetadata('foo'));
    }

    #[Test]
    public function looksUpByAlias(): void
    {
        $handler = $this->createMockHandler();
        $metadata = new CommandMetadata(name: 'foo', aliases: ['f', 'bar']);

        $this->registry->register($metadata, $handler);

        $this->assertTrue($this->registry->has('f'));
        $this->assertTrue($this->registry->has('bar'));
        $this->assertSame($metadata, $this->registry->getMetadata('f'));
        $this->assertSame($metadata, $this->registry->getMetadata('bar'));
    }

    #[Test]
    public function hasReturnsFalseForUnregisteredName(): void
    {
        $this->assertFalse($this->registry->has('nonexistent'));
    }

    #[Test]
    public function getMetadataReturnsNullForUnregisteredName(): void
    {
        $this->assertNull($this->registry->getMetadata('nonexistent'));
    }

    #[Test]
    public function throwsWhenRegisteringDuplicateName(): void
    {
        $this->registry->register(
            new CommandMetadata(name: 'dup'),
            $this->createMockHandler(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Command 'dup' is already registered.");

        $this->registry->register(
            new CommandMetadata(name: 'dup'),
            $this->createMockHandler(),
        );
    }

    #[Test]
    public function throwsWhenAliasConflictsWithExistingAlias(): void
    {
        $this->registry->register(
            new CommandMetadata(name: 'first', aliases: ['shared']),
            $this->createMockHandler(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Alias 'shared' is already registered for command 'first'.");

        $this->registry->register(
            new CommandMetadata(name: 'second', aliases: ['shared']),
            $this->createMockHandler(),
        );
    }

    #[Test]
    public function throwsWhenAliasConflictsWithExistingCommandName(): void
    {
        $this->registry->register(
            new CommandMetadata(name: 'existing'),
            $this->createMockHandler(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Alias 'existing' conflicts with registered command name.");

        $this->registry->register(
            new CommandMetadata(name: 'other', aliases: ['existing']),
            $this->createMockHandler(),
        );
    }

    // ─── Built-in: /help ─────────────────────────────────────────────

    #[Test]
    public function executeHelpListsAllRegisteredCommands(): void
    {
        $result = $this->registry->execute(new SlashCommand('help', '', '/help'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Available commands:', $result->text);
        $this->assertStringContainsString('/help', $result->text);
        $this->assertStringContainsString('/clear', $result->text);
        $this->assertStringContainsString('/exit', $result->text);
    }

    #[Test]
    public function executeHelpListsCustomRegisteredCommands(): void
    {
        $this->registry->register(
            new CommandMetadata(name: 'custom', description: 'A custom command'),
            $this->createMockHandler(),
        );

        $result = $this->registry->execute(new SlashCommand('help', '', '/help'));

        $this->assertStringContainsString('A custom command', $result->text);
        $this->assertStringContainsString('/custom', $result->text);
    }

    #[Test]
    public function executeHelpWithAliasShowsAliasesInList(): void
    {
        $this->registry->register(
            new CommandMetadata(name: 'custom', aliases: ['c', 'cmd'], description: 'Custom'),
            $this->createMockHandler(),
        );

        $result = $this->registry->execute(new SlashCommand('help', '', '/help'));

        $this->assertStringContainsString('(c, cmd)', $result->text);
    }

    #[Test]
    public function executeHelpWithCommandNameShowsDetailedHelp(): void
    {
        $result = $this->registry->execute(new SlashCommand('help', 'clear', '/help clear'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Command: /clear', $result->text);
        $this->assertStringContainsString('Clear the conversation transcript', $result->text);
        $this->assertStringNotContainsString('Available commands:', $result->text);
    }

    #[Test]
    public function executeHelpWithUnknownCommandFallsBackToGeneralHelp(): void
    {
        // `/help nonexistent` falls back to the general help listing
        // instead of returning an "Unknown command" error.
        $result = $this->registry->execute(new SlashCommand('help', 'nonexistent', '/help nonexistent'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Available commands:', $result->text);
        $this->assertStringNotContainsString('Unknown command: /nonexistent', $result->text);
    }

    #[Test]
    public function executeHelpWithRandomArgFallsBackToGeneralHelp(): void
    {
        // `/help 123` should NOT report "Unknown command: /123".
        $result = $this->registry->execute(new SlashCommand('help', '123', '/help 123'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Available commands:', $result->text);
        $this->assertStringNotContainsString('Unknown command: /123', $result->text);
        $this->assertStringNotContainsString('/123', $result->text);
    }

    #[Test]
    public function executeHelpViaAlias(): void
    {
        // '?' is an alias for 'help'
        $result = $this->registry->execute(new SlashCommand('?', '', '/?'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Available commands:', $result->text);
    }

    #[Test]
    public function executeHelpViaHAlias(): void
    {
        $result = $this->registry->execute(new SlashCommand('h', '', '/h'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Available commands:', $result->text);
    }

    #[Test]
    public function customHelpHandlerOverridesBuiltIn(): void
    {
        $handler = new FixedMessageTestHandler('CUSTOM HELP OUTPUT');

        $this->registry->setHandler('help', $handler);

        $result = $this->registry->execute(new SlashCommand('help', '', '/help'));

        $this->assertSame('CUSTOM HELP OUTPUT', $result->text);
    }

    #[Test]
    public function setHandlerThrowsForUnregisteredName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot set handler: command 'nope' is not registered.");

        $this->registry->setHandler('nope', $this->createMockHandler());
    }

    #[Test]
    public function setHandlerViaAliasWorks(): void
    {
        $this->registry->register(
            new CommandMetadata(name: 'mycmd', aliases: ['mc']),
            $this->createMockHandler(),
        );

        $handler = new FixedMessageTestHandler('replaced');

        $this->registry->setHandler('mc', $handler);

        $result = $this->registry->execute(new SlashCommand('mycmd', '', '/mycmd'));
        $this->assertSame('replaced', $result->text);
    }

    // ─── Built-in: /hotkeys table ────────────────────────────────────

    #[Test]
    public function executeHotkeysReturnsHotkeyTableDataForEmptyRegistry(): void
    {
        // Default registry has an empty HotkeyRegistry → HotkeyTableData with isEmpty=true
        $result = $this->registry->execute(new SlashCommand('hotkeys', '', '/hotkeys'));

        $this->assertInstanceOf(
            \Ineersa\Tui\Command\Hotkey\HotkeyTableData::class,
            $result,
        );
        // @phpstan-ignore-next-line
        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function executeHotkeysReturnsHotkeyTableDataWithGroupedBindings(): void
    {
        $hotkeyReg = new \Ineersa\Tui\Command\Hotkey\HotkeyRegistry();
        $hotkeyReg->add(new \Ineersa\Tui\Command\Hotkey\HotkeyBindingDTO(
            context: 'Global',
            keys: ['ctrl+c'],
            action: 'Clear editor / cancel',
            description: 'Clear or double-exit',
            priority: 10,
        ));
        $hotkeyReg->add(new \Ineersa\Tui\Command\Hotkey\HotkeyBindingDTO(
            context: 'Editor',
            keys: ['enter'],
            action: 'Submit prompt',
            description: 'Send editor content',
            priority: 10,
        ));
        $hotkeyReg->add(new \Ineersa\Tui\Command\Hotkey\HotkeyBindingDTO(
            context: 'Editor',
            keys: ['ctrl+j', 'shift+enter'],
            action: 'Insert newline',
            description: 'Start a new line',
            priority: 20,
        ));

        $reg = new SlashCommandRegistry($hotkeyReg);
        $result = $reg->execute(new SlashCommand('hotkeys', '', '/hotkeys'));

        $this->assertInstanceOf(
            \Ineersa\Tui\Command\Hotkey\HotkeyTableData::class,
            $result,
        );

        // @phpstan-ignore-next-line
        $this->assertFalse($result->isEmpty());

        // @phpstan-ignore-next-line
        $groups = $result->groups;
        $this->assertArrayHasKey('Global', $groups);
        $this->assertArrayHasKey('Editor', $groups);

        // Check representative hotkey data is present in the groups
        $globalBindings = $groups['Global'];
        $this->assertCount(1, $globalBindings);
        $this->assertSame('Clear editor / cancel', $globalBindings[0]->action);

        $editorBindings = $groups['Editor'];
        $this->assertCount(2, $editorBindings);
        $actions = array_map(
            static fn (\Ineersa\Tui\Command\Hotkey\HotkeyBindingDTO $b): string => $b->action,
            $editorBindings,
        );
        $this->assertContains('Submit prompt', $actions);
        $this->assertContains('Insert newline', $actions);
    }

    #[Test]
    public function executeHotkeysViaAlias(): void
    {
        // 'hk' is an alias for 'hotkeys'
        $result = $this->registry->execute(new SlashCommand('hk', '', '/hk'));

        $this->assertInstanceOf(
            \Ineersa\Tui\Command\Hotkey\HotkeyTableData::class,
            $result,
        );
        // @phpstan-ignore-next-line
        $this->assertTrue($result->isEmpty());
    }

    // ─── Built-in: /clear ────────────────────────────────────────────

    #[Test]
    public function executeClearReturnsClearTranscript(): void
    {
        $result = $this->registry->execute(new SlashCommand('clear', '', '/clear'));

        $this->assertInstanceOf(ClearTranscript::class, $result);
    }

    #[Test]
    public function executeClearViaAlias(): void
    {
        $result = $this->registry->execute(new SlashCommand('cls', '', '/cls'));

        $this->assertInstanceOf(ClearTranscript::class, $result);
    }

    // ─── Built-in: /exit ─────────────────────────────────────────────

    #[Test]
    public function executeExitReturnsExitApplication(): void
    {
        $result = $this->registry->execute(new SlashCommand('exit', '', '/exit'));

        $this->assertInstanceOf(ExitApplication::class, $result);
    }

    #[Test]
    public function executeExitViaAlias(): void
    {
        $result = $this->registry->execute(new SlashCommand('quit', '', '/quit'));

        $this->assertInstanceOf(ExitApplication::class, $result);
    }

    #[Test]
    public function executeExitViaQAlias(): void
    {
        $result = $this->registry->execute(new SlashCommand('q', '', '/q'));

        $this->assertInstanceOf(ExitApplication::class, $result);
    }

    // ─── Unknown commands ────────────────────────────────────────────

    #[Test]
    public function executeUnknownCommandReturnsTranscriptMessage(): void
    {
        $result = $this->registry->execute(new SlashCommand('unknown', 'arg', '/unknown arg'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Unknown command: /unknown', $result->text);
        $this->assertStringContainsString('/help', $result->text);
        $this->assertSame('system', $result->role);
        $this->assertSame('muted', $result->style);
    }

    #[Test]
    public function executeUnknownCommandDoesNotThrow(): void
    {
        // Should not throw — returns typed result instead
        $result = $this->registry->execute(new SlashCommand('garbage', '', '/garbage'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
    }

    // ─── Custom handler execution ────────────────────────────────────

    #[Test]
    public function executeDispatchesToRegisteredHandler(): void
    {
        $this->registry->register(
            new CommandMetadata(name: 'noop', description: 'Does nothing'),
            $this->createMockHandler(),
        );

        $result = $this->registry->execute(new SlashCommand('noop', '', '/noop'));

        $this->assertInstanceOf(NoOp::class, $result);
    }

    #[Test]
    public function executeViaAliasDispatchesToCanonicalHandler(): void
    {
        $handler = new EchoHandler();

        $this->registry->register(
            new CommandMetadata(name: 'echo', aliases: ['e'], description: 'Echo args', acceptsArguments: true),
            $handler,
        );

        $result = $this->registry->execute(new SlashCommand('e', 'hello world', '/e hello world'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('got args: hello world', $result->text);
    }

    // ─── Argument expectations ────────────────────────────────────────

    #[Test]
    public function noArgCommandIgnoresExtraArgs(): void
    {
        // /clear (acceptsArguments=false by default) — args are stripped.
        $result = $this->registry->execute(new SlashCommand('clear', 'whatever', '/clear whatever'));

        $this->assertInstanceOf(ClearTranscript::class, $result);
    }

    #[Test]
    public function exitCommandIgnoresExtraArgs(): void
    {
        // /exit (acceptsArguments=false by default) — args are stripped.
        $result = $this->registry->execute(new SlashCommand('exit', 'now', '/exit now'));

        $this->assertInstanceOf(ExitApplication::class, $result);
    }

    #[Test]
    public function argAcceptingCommandReceivesArgs(): void
    {
        $handler = new EchoHandler();
        $this->registry->register(
            new CommandMetadata(name: 'argcmd', description: 'Accepts args', acceptsArguments: true),
            $handler,
        );

        $result = $this->registry->execute(new SlashCommand('argcmd', 'hello world', '/argcmd hello world'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('got args: hello world', $result->text);
    }

    #[Test]
    public function defaultCustomCommandDoesNotAcceptArgs(): void
    {
        // Default acceptsArguments=false: handler receives empty args even
        // if the user typed extras.
        $handler = new EchoHandler();
        $this->registry->register(
            new CommandMetadata(name: 'noarg', description: 'Does not accept args'),
            $handler,
        );

        $result = $this->registry->execute(new SlashCommand('noarg', 'stripped', '/noarg stripped'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('got args: (none)', $result->text);
    }

    // ─── Metadata access ─────────────────────────────────────────────

    #[Test]
    public function allMetadataReturnsSortedList(): void
    {
        // Register out of order
        $this->registry->register(
            new CommandMetadata(name: 'zebra'),
            $this->createMockHandler(),
        );
        $this->registry->register(
            new CommandMetadata(name: 'alpha'),
            $this->createMockHandler(),
        );

        $all = $this->registry->allMetadata();
        $names = array_map(static fn (CommandMetadata $m) => $m->name, $all);

        // Should be sorted alphabetically
        $this->assertSame(['alpha', 'clear', 'exit', 'help', 'hotkeys', 'zebra'], $names);
    }

    #[Test]
    public function allMetadataMapReturnsNameToMetadataMap(): void
    {
        $this->registry->register(
            new CommandMetadata(name: 'custom'),
            $this->createMockHandler(),
        );

        $map = $this->registry->allMetadataMap();

        $this->assertArrayHasKey('help', $map);
        $this->assertArrayHasKey('clear', $map);
        $this->assertArrayHasKey('exit', $map);
        $this->assertArrayHasKey('hotkeys', $map);
        $this->assertArrayHasKey('custom', $map);
    }

    #[Test]
    public function countReflectsRegisteredCommands(): void
    {
        $this->assertSame(4, $this->registry->count()); // help, clear, exit, hotkeys

        $this->registry->register(
            new CommandMetadata(name: 'extra'),
            $this->createMockHandler(),
        );

        $this->assertSame(5, $this->registry->count());
    }

    // ─── Built-in command metadata ───────────────────────────────────

    #[Test]
    public function builtInHelpHasCorrectMetadata(): void
    {
        $meta = $this->registry->getMetadata('help');

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
        $meta = $this->registry->getMetadata('clear');

        $this->assertNotNull($meta);
        $this->assertSame('clear', $meta->name);
        $this->assertContains('cls', $meta->aliases);
        $this->assertNotEmpty($meta->description);
    }

    #[Test]
    public function builtInExitHasCorrectMetadata(): void
    {
        $meta = $this->registry->getMetadata('exit');

        $this->assertNotNull($meta);
        $this->assertSame('exit', $meta->name);
        $this->assertContains('quit', $meta->aliases);
        $this->assertContains('q', $meta->aliases);
        $this->assertNotEmpty($meta->description);
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function createMockHandler(): SlashCommandHandler
    {
        return new NoOpTestHandler();
    }
}
