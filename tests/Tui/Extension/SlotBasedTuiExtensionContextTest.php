<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Extension;

use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Extension\SlotBasedTuiExtensionContext;
use Ineersa\Tui\Footer\FooterDataProvider;
use Ineersa\Tui\Layout\InputPriority;
use Ineersa\Tui\Layout\TuiSlotRegistry;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Tui;

#[CoversClass(SlotBasedTuiExtensionContext::class)]
final class SlotBasedTuiExtensionContextTest extends TestCase
{
    private TuiSlotRegistry $registry;
    private SlotBasedTuiExtensionContext $context;

    /** @var list<array{0: string, 1: ?string}> */
    private array $statusCalls = [];

    /** @var list<?string> */
    private array $workingMessageCalls = [];

    /** @var list<bool> */
    private array $workingVisibleCalls = [];

    protected function setUp(): void
    {
        $this->registry = new TuiSlotRegistry();
        $this->statusCalls = [];
        $this->workingMessageCalls = [];
        $this->workingVisibleCalls = [];
        $this->context = new SlotBasedTuiExtensionContext(
            $this->registry,
            new FooterDataProvider(),
            function (string $key, ?string $text): void {
                $this->statusCalls[] = [$key, $text];
            },
            function (?string $message): void {
                $this->workingMessageCalls[] = $message;
            },
            function (bool $visible): void {
                $this->workingVisibleCalls[] = $visible;
            },
        );
    }

    public function testSetStatusRoutesThroughClosure(): void
    {
        $this->context->setStatus('key', 'value');
        $this->context->setStatus('key', null);

        $this->assertSame([['key', 'value'], ['key', null]], $this->statusCalls);
        $this->assertSame([], $this->registry->getInputHandlers(), 'status must not touch the registry');
    }

    public function testSetWorkingMessageRoutesThroughClosure(): void
    {
        $this->context->setWorkingMessage('Busy');
        $this->context->setWorkingMessage(null);

        $this->assertSame(['Busy', null], $this->workingMessageCalls);
    }

    public function testSetWorkingVisibleRoutesThroughClosure(): void
    {
        $this->context->setWorkingVisible(false);
        $this->context->setWorkingVisible(true);

        $this->assertSame([false, true], $this->workingVisibleCalls);
    }

    public function testOnTerminalInput(): void
    {
        $handler = static function (InputEvent $event): void {};
        $this->context->onTerminalInput($handler);

        $handlers = $this->registry->getInputHandlers();
        $this->assertCount(1, $handlers);
        $this->assertSame($handler, $handlers[0]['handler']);
        $this->assertSame(InputPriority::EXTENSION_DEFAULT, $handlers[0]['priority']);
    }

    public function testOnTerminalInputWithExplicitPriority(): void
    {
        $handler = static function (InputEvent $event): void {};
        $this->context->onTerminalInput($handler, InputPriority::MODEL_CONTROL);

        $handlers = $this->registry->getInputHandlers();
        $this->assertSame(InputPriority::MODEL_CONTROL, $handlers[0]['priority']);
    }

    public function testSlotHandlersInterleaveByNativePriorityAndCanStopPropagation(): void
    {
        $editor = new PromptEditor();
        $theme = new DefaultTheme(new ThemePalette('default'));
        $screen = new ChatScreen($theme, 'test-session', $editor);
        $tui = new Tui();
        $screen->mount($tui);

        $order = [];
        $screen->extensionContext()->onTerminalInput(
            static function (InputEvent $event) use (&$order): void {
                $order[] = 'extension-tier';
            },
            InputPriority::EXTENSION_DEFAULT,
        );
        $screen->extensionContext()->onTerminalInput(
            static function (InputEvent $event) use (&$order): void {
                $order[] = 'model-tier';
                $event->stopPropagation();
            },
            InputPriority::MODEL_CONTROL,
        );

        $screen->registerSlotInputListeners();

        $tui->handleInput('x');

        // Higher native priority runs first; stopPropagation prevents the
        // lower-priority handler (and the focused editor) from seeing the input.
        $this->assertSame(['model-tier'], $order);
    }

    public function testSlotHandlersKeepStableRegistrationOrderAtEqualPriority(): void
    {
        $editor = new PromptEditor();
        $theme = new DefaultTheme(new ThemePalette('default'));
        $screen = new ChatScreen($theme, 'test-session', $editor);
        $tui = new Tui();
        $screen->mount($tui);

        $order = [];
        $screen->extensionContext()->onTerminalInput(static function (InputEvent $event) use (&$order): void {
            $order[] = 'first';
        });
        $screen->extensionContext()->onTerminalInput(static function (InputEvent $event) use (&$order): void {
            $order[] = 'second';
        });

        $screen->registerSlotInputListeners();

        $tui->handleInput('x');

        $this->assertSame(['first', 'second'], $order);
    }
}
