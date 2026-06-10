<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Command;

use Ineersa\Tui\Command\ClearScreenCommand;
use Ineersa\Tui\Command\ClearTranscript;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\ExitApplication;
use Ineersa\Tui\Command\ExitTuiCommand;
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

        self::assertTrue($this->registry->has('foo'));
        self::assertSame($metadata, $this->registry->getMetadata('foo'));
    }

    #[Test]
    public function looksUpByAlias(): void
    {
        $handler = $this->createMockHandler();
        $metadata = new CommandMetadata(name: 'foo', aliases: ['f', 'bar']);

        $this->registry->register($metadata, $handler);

        self::assertTrue($this->registry->has('f'));
        self::assertTrue($this->registry->has('bar'));
        self::assertSame($metadata, $this->registry->getMetadata('f'));
        self::assertSame($metadata, $this->registry->getMetadata('bar'));
    }

    #[Test]
    public function hasReturnsFalseForUnregisteredName(): void
    {
        self::assertFalse($this->registry->has('nonexistent'));
    }

    #[Test]
    public function getMetadataReturnsNullForUnregisteredName(): void
    {
        self::assertNull($this->registry->getMetadata('nonexistent'));
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

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Available commands:', $result->text);
        self::assertStringContainsString('/help', $result->text);
        self::assertStringContainsString('/clear', $result->text);
        self::assertStringContainsString('/exit', $result->text);
    }

    #[Test]
    public function executeHelpListsCustomRegisteredCommands(): void
    {
        $this->registry->register(
            new CommandMetadata(name: 'custom', description: 'A custom command'),
            $this->createMockHandler(),
        );

        $result = $this->registry->execute(new SlashCommand('help', '', '/help'));

        self::assertStringContainsString('A custom command', $result->text);
        self::assertStringContainsString('/custom', $result->text);
    }

    #[Test]
    public function executeHelpWithAliasShowsAliasesInList(): void
    {
        $this->registry->register(
            new CommandMetadata(name: 'custom', aliases: ['c', 'cmd'], description: 'Custom'),
            $this->createMockHandler(),
        );

        $result = $this->registry->execute(new SlashCommand('help', '', '/help'));

        self::assertStringContainsString('(c, cmd)', $result->text);
    }

    #[Test]
    public function executeHelpWithCommandNameShowsDetailedHelp(): void
    {
        $result = $this->registry->execute(new SlashCommand('help', 'clear', '/help clear'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Command: /clear', $result->text);
        self::assertStringContainsString('Clear the conversation transcript', $result->text);
        self::assertStringNotContainsString('Available commands:', $result->text);
    }

    #[Test]
    public function executeHelpWithUnknownCommandFallsBackToGeneralHelp(): void
    {
        // `/help nonexistent` falls back to the general help listing
        // instead of returning an "Unknown command" error.
        $result = $this->registry->execute(new SlashCommand('help', 'nonexistent', '/help nonexistent'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Available commands:', $result->text);
        self::assertStringNotContainsString('Unknown command: /nonexistent', $result->text);
    }

    #[Test]
    public function executeHelpWithRandomArgFallsBackToGeneralHelp(): void
    {
        // `/help 123` should NOT report "Unknown command: /123".
        $result = $this->registry->execute(new SlashCommand('help', '123', '/help 123'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Available commands:', $result->text);
        self::assertStringNotContainsString('Unknown command: /123', $result->text);
        self::assertStringNotContainsString('/123', $result->text);
    }

    #[Test]
    public function executeHelpViaAlias(): void
    {
        // '?' is an alias for 'help'
        $result = $this->registry->execute(new SlashCommand('?', '', '/?'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Available commands:', $result->text);
    }

    #[Test]
    public function executeHelpViaHAlias(): void
    {
        $result = $this->registry->execute(new SlashCommand('h', '', '/h'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Available commands:', $result->text);
    }

    #[Test]
    public function customHelpHandlerOverridesBuiltIn(): void
    {
        $handler = new FixedMessageTestHandler('CUSTOM HELP OUTPUT');

        $this->registry->setHandler('help', $handler);

        $result = $this->registry->execute(new SlashCommand('help', '', '/help'));

        self::assertSame('CUSTOM HELP OUTPUT', $result->text);
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
        self::assertSame('replaced', $result->text);
    }

    // ─── Built-in: /hotkeys table ────────────────────────────────────

    #[Test]
    public function executeHotkeysReturnsSystemTranscriptMessage(): void
    {
        // Default registry has an empty HotkeyRegistry → empty message
        $result = $this->registry->execute(new SlashCommand('hotkeys', '', '/hotkeys'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('No hotkey hints registered', $result->text);
    }

    #[Test]
    public function executeHotkeysRendersBoxDrawingTable(): void
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

        self::assertInstanceOf(TranscriptMessage::class, $result);

        // Box-drawing chars must be present
        self::assertStringContainsString('┌', $result->text, 'Should contain top-left corner');
        self::assertStringContainsString('├', $result->text, 'Should contain header-body separator');
        self::assertStringContainsString('└', $result->text, 'Should contain bottom-left corner');
        self::assertStringContainsString('│', $result->text, 'Should contain vertical border');

        // Header
        self::assertStringContainsString('Keyboard shortcuts', $result->text);
        self::assertStringContainsString('Keys', $result->text);
        self::assertStringContainsString('Action', $result->text);
        self::assertStringContainsString('Description', $result->text);

        // Section headings
        self::assertStringContainsString('Global', $result->text);
        self::assertStringContainsString('Editor', $result->text);

        // Representative hotkeys
        self::assertStringContainsString('Ctrl+C', $result->text);
        self::assertStringContainsString('Ctrl+J', $result->text);
        self::assertStringContainsString('Shift+Enter', $result->text);
        self::assertStringContainsString('Submit prompt', $result->text);
        self::assertStringContainsString('Insert newline', $result->text);
        self::assertStringContainsString('Clear editor / cancel', $result->text);
    }

    #[Test]
    public function executeHotkeysViaAlias(): void
    {
        // 'hk' is an alias for 'hotkeys'
        $result = $this->registry->execute(new SlashCommand('hk', '', '/hk'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        // Empty registry → the "no hints" message
        self::assertStringContainsString('No hotkey hints registered', $result->text);
    }

    // ─── Built-in: /clear ────────────────────────────────────────────

    #[Test]
    public function executeClearReturnsClearTranscript(): void
    {
        $result = $this->registry->execute(new SlashCommand('clear', '', '/clear'));

        self::assertInstanceOf(ClearTranscript::class, $result);
    }

    #[Test]
    public function executeClearViaAlias(): void
    {
        $result = $this->registry->execute(new SlashCommand('cls', '', '/cls'));

        self::assertInstanceOf(ClearTranscript::class, $result);
    }

    // ─── Built-in: /exit ─────────────────────────────────────────────

    #[Test]
    public function executeExitReturnsExitApplication(): void
    {
        $result = $this->registry->execute(new SlashCommand('exit', '', '/exit'));

        self::assertInstanceOf(ExitApplication::class, $result);
    }

    #[Test]
    public function executeExitViaAlias(): void
    {
        $result = $this->registry->execute(new SlashCommand('quit', '', '/quit'));

        self::assertInstanceOf(ExitApplication::class, $result);
    }

    #[Test]
    public function executeExitViaQAlias(): void
    {
        $result = $this->registry->execute(new SlashCommand('q', '', '/q'));

        self::assertInstanceOf(ExitApplication::class, $result);
    }

    // ─── Unknown commands ────────────────────────────────────────────

    #[Test]
    public function executeUnknownCommandReturnsTranscriptMessage(): void
    {
        $result = $this->registry->execute(new SlashCommand('unknown', 'arg', '/unknown arg'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Unknown command: /unknown', $result->text);
        self::assertStringContainsString('/help', $result->text);
        self::assertSame('system', $result->role);
        self::assertSame('muted', $result->style);
    }

    #[Test]
    public function executeUnknownCommandDoesNotThrow(): void
    {
        // Should not throw — returns typed result instead
        $result = $this->registry->execute(new SlashCommand('garbage', '', '/garbage'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
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

        self::assertInstanceOf(NoOp::class, $result);
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

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('got args: hello world', $result->text);
    }

    // ─── Argument expectations ────────────────────────────────────────

    #[Test]
    public function noArgCommandIgnoresExtraArgs(): void
    {
        // /clear (acceptsArguments=false by default) — args are stripped.
        $result = $this->registry->execute(new SlashCommand('clear', 'whatever', '/clear whatever'));

        self::assertInstanceOf(ClearTranscript::class, $result);
    }

    #[Test]
    public function exitCommandIgnoresExtraArgs(): void
    {
        // /exit (acceptsArguments=false by default) — args are stripped.
        $result = $this->registry->execute(new SlashCommand('exit', 'now', '/exit now'));

        self::assertInstanceOf(ExitApplication::class, $result);
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

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('got args: hello world', $result->text);
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

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('got args: (none)', $result->text);
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
        self::assertSame(['alpha', 'clear', 'exit', 'help', 'hotkeys', 'zebra'], $names);
    }

    #[Test]
    public function allMetadataMapReturnsNameToMetadataMap(): void
    {
        $this->registry->register(
            new CommandMetadata(name: 'custom'),
            $this->createMockHandler(),
        );

        $map = $this->registry->allMetadataMap();

        self::assertArrayHasKey('help', $map);
        self::assertArrayHasKey('clear', $map);
        self::assertArrayHasKey('exit', $map);
        self::assertArrayHasKey('hotkeys', $map);
        self::assertArrayHasKey('custom', $map);
    }

    #[Test]
    public function countReflectsRegisteredCommands(): void
    {
        self::assertSame(4, $this->registry->count()); // help, clear, exit, hotkeys

        $this->registry->register(
            new CommandMetadata(name: 'extra'),
            $this->createMockHandler(),
        );

        self::assertSame(5, $this->registry->count());
    }

    // ─── Built-in command metadata ───────────────────────────────────

    #[Test]
    public function builtInHelpHasCorrectMetadata(): void
    {
        $meta = $this->registry->getMetadata('help');

        self::assertNotNull($meta);
        self::assertSame('help', $meta->name);
        self::assertContains('h', $meta->aliases);
        self::assertContains('?', $meta->aliases);
        self::assertNotEmpty($meta->description);
        self::assertNotEmpty($meta->usage);
    }

    #[Test]
    public function builtInClearHasCorrectMetadata(): void
    {
        $meta = $this->registry->getMetadata('clear');

        self::assertNotNull($meta);
        self::assertSame('clear', $meta->name);
        self::assertContains('cls', $meta->aliases);
        self::assertNotEmpty($meta->description);
    }

    #[Test]
    public function builtInExitHasCorrectMetadata(): void
    {
        $meta = $this->registry->getMetadata('exit');

        self::assertNotNull($meta);
        self::assertSame('exit', $meta->name);
        self::assertContains('quit', $meta->aliases);
        self::assertContains('q', $meta->aliases);
        self::assertNotEmpty($meta->description);
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function createMockHandler(): SlashCommandHandler
    {
        return new NoOpTestHandler();
    }
}
