<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Command;

use Ineersa\Tui\Command\ClearTranscript;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\ExitApplication;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\TranscriptMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SlashCommandRegistry::class)]
final class SlashCommandRegistryTest extends TestCase
{
    private SlashCommandCatalog $catalog;
    private SlashCommandRegistry $registry;

    protected function setUp(): void
    {
        $this->catalog = new SlashCommandCatalog();
        $this->registry = new SlashCommandRegistry($this->catalog);
    }

    // ─── Session handler binding ─────────────────────────────────────

    #[Test]
    public function bindThrowsForUnregisteredName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot bind handler: command 'nope' is not registered.");

        $this->registry->bind('nope', $this->createMockHandler());
    }

    #[Test]
    public function bindViaAliasBindsCanonicalHandler(): void
    {
        $this->catalog->register(
            new CommandMetadata(name: 'mycmd', aliases: ['mc']),
            $this->createMockHandler(),
        );

        $this->registry->bind('mc', new FixedMessageTestHandler('replaced'));

        $result = $this->registry->execute(new SlashCommand('mycmd', '', '/mycmd'));
        $this->assertSame('replaced', $result->text);
    }

    #[Test]
    public function sessionHandlerOverridesCatalogDefault(): void
    {
        // Catalog default handler exists (e.g. extension command)…
        $this->catalog->register(
            new CommandMetadata(name: 'hybrid', description: 'Has default and session handler'),
            new FixedMessageTestHandler('default'),
        );

        $this->assertSame('default', $this->registry->execute(new SlashCommand('hybrid', '', '/hybrid'))->text);

        // …and the session binds its own handler for this iteration.
        $this->registry->bind('hybrid', new FixedMessageTestHandler('session'));

        $this->assertSame('session', $this->registry->execute(new SlashCommand('hybrid', '', '/hybrid'))->text);
    }

    #[Test]
    public function unbindableSessionRegistryStillResolvesCatalogDefault(): void
    {
        // A fresh registry that never binds the command still resolves the
        // process-owned default handler (extension/prompt-template commands).
        $this->catalog->register(
            new CommandMetadata(name: 'ext', description: 'Extension command'),
            new FixedMessageTestHandler('extension'),
        );

        $freshRegistry = new SlashCommandRegistry($this->catalog);
        $this->assertSame('extension', $freshRegistry->execute(new SlashCommand('ext', '', '/ext'))->text);
    }

    #[Test]
    public function bindDoesNotLeakToAnotherRegistryInstance(): void
    {
        $this->catalog->register(
            new CommandMetadata(name: 'session-cmd', description: 'Per-session handler'),
            new FixedMessageTestHandler('default'),
        );

        $other = new SlashCommandRegistry($this->catalog);
        $this->registry->bind('session-cmd', new FixedMessageTestHandler('session'));

        $this->assertSame('session', $this->registry->execute(new SlashCommand('session-cmd', '', '/session-cmd'))->text);
        $this->assertSame('default', $other->execute(new SlashCommand('session-cmd', '', '/session-cmd'))->text);
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
        $this->catalog->register(
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
        $this->catalog->register(
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

        $this->registry->bind('help', $handler);

        $result = $this->registry->execute(new SlashCommand('help', '', '/help'));

        $this->assertSame('CUSTOM HELP OUTPUT', $result->text);
    }

    // ─── Built-in: /hotkeys table ────────────────────────────────────

    #[Test]
    public function executeHotkeysReturnsHotkeyTableDataForEmptyRegistry(): void
    {
        // Default catalog has an empty HotkeyRegistry.
        $result = $this->registry->execute(new SlashCommand('hotkeys', '', '/hotkeys'));

        $this->assertInstanceOf(
            \Ineersa\Tui\Command\Hotkey\HotkeyTableData::class,
            $result,
        );
        $this->assertSame([], $result->groups);
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

        $reg = new SlashCommandRegistry(new SlashCommandCatalog($hotkeyReg));
        $result = $reg->execute(new SlashCommand('hotkeys', '', '/hotkeys'));

        $this->assertInstanceOf(
            \Ineersa\Tui\Command\Hotkey\HotkeyTableData::class,
            $result,
        );

        $this->assertNotSame([], $result->groups);

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
        $this->assertSame([], $result->groups);
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
    public function executeDispatchesToCatalogHandler(): void
    {
        $this->catalog->register(
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

        $this->catalog->register(
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
        $this->catalog->register(
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
        $this->catalog->register(
            new CommandMetadata(name: 'noarg', description: 'Does not accept args'),
            $handler,
        );

        $result = $this->registry->execute(new SlashCommand('noarg', 'stripped', '/noarg stripped'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('got args: (none)', $result->text);
    }

    // ─── Private helpers ─────────────────────────────────────────────

    private function createMockHandler(): SlashCommandHandler
    {
        return new NoOpTestHandler();
    }
}
