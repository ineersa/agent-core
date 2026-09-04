<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\Tui\Listener\CtrlCInputInterceptor;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Terminal\ScreenBuffer;

/**
 * Test thesis: Ctrl+C / Ctrl+D global interrupt matching accepts both legacy
 * control bytes and Kitty CSI-u sequences through Symfony Keybindings.
 */
#[CoversClass(CtrlCInputInterceptor::class)]
final class CtrlCInputInterceptorTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideCtrlCSequences(): iterable
    {
        yield 'legacy' => ["\x03"];
        yield 'kitty press' => ["\x1b[99;5u"];
        yield 'kitty repeat' => ["\x1b[99;5:2u"];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideCtrlDSequences(): iterable
    {
        yield 'legacy' => ["\x04"];
        yield 'kitty press' => ["\x1b[100;5u"];
        yield 'kitty repeat' => ["\x1b[100;5:2u"];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideKittyControlReleaseSequences(): iterable
    {
        yield 'Ctrl+C' => ["\x1b[99;5:3u"];
        yield 'Ctrl+D' => ["\x1b[100;5:3u"];
    }

    #[Test]
    #[DataProvider('provideCtrlDSequences')]
    public function ctrlDStopsTui(string $sequence): void
    {
        $harness = $this->startHarness();
        $this->assertTrue($harness->tui()->isRunning());

        $harness->sendInput($sequence);

        $this->assertFalse($harness->tui()->isRunning());
        $harness->stopInputLoop();
    }

    #[Test]
    #[DataProvider('provideCtrlCSequences')]
    public function ctrlCClearsNonEmptyEditor(string $sequence): void
    {
        $harness = $this->startHarness();
        $harness->screen()->promptEditor()->typeText('draft text');
        $harness->render();
        $this->assertStringContainsString('draft text', $this->currentScreenText($harness));

        $harness->sendInput($sequence);

        $this->assertSame('', $harness->screen()->editorText());
        $this->assertStringNotContainsString('draft text', $this->currentScreenText($harness));
        $this->assertTrue($harness->tui()->isRunning());
        $this->assertArrayNotHasKey('ctrl_c', $this->statusEntries($harness));
        $harness->stopInputLoop();
    }

    #[Test]
    #[DataProvider('provideCtrlCSequences')]
    public function ctrlCOnEmptyEditorShowsExitPrompt(string $sequence): void
    {
        $harness = $this->startHarness();

        $harness->sendInput($sequence);

        $this->assertTrue($harness->tui()->isRunning());
        $this->assertSame(
            'Press Ctrl+C again to exit',
            $this->statusEntries($harness)['ctrl_c'] ?? null,
        );
        $harness->stopInputLoop();
    }

    #[Test]
    public function anotherKeyResetsDoublePressBeforeTwoCtrlCPressesExit(): void
    {
        $harness = $this->startHarness();

        $harness->sendInput("\x03");
        $harness->sendInput('a');
        $this->assertArrayNotHasKey('ctrl_c', $this->statusEntries($harness));

        $harness->screen()->clearEditor();
        $harness->sendInput("\x03");
        $harness->sendInput("\x03");

        $this->assertFalse($harness->tui()->isRunning());
        $harness->stopInputLoop();
    }

    #[Test]
    public function kittyReleaseDoesNotResetDoublePressState(): void
    {
        $harness = $this->startHarness();

        $harness->sendInput("\x1b[99;5u");
        $harness->sendInput("\x1b[99;5:3u");

        $this->assertSame(
            'Press Ctrl+C again to exit',
            $this->statusEntries($harness)['ctrl_c'] ?? null,
        );

        $harness->sendInput("\x1b[99;5u");

        $this->assertFalse($harness->tui()->isRunning());
        $harness->stopInputLoop();
    }

    #[Test]
    #[DataProvider('provideKittyControlReleaseSequences')]
    public function kittyControlReleaseDoesNotMutateEditorOrQuit(string $sequence): void
    {
        $harness = $this->startHarness();
        $harness->screen()->promptEditor()->typeText('draft text');

        $harness->sendInput($sequence);

        $this->assertSame('draft text', $harness->screen()->editorText());
        $this->assertTrue($harness->tui()->isRunning());
        $this->assertArrayNotHasKey('ctrl_c', $this->statusEntries($harness));
        $harness->stopInputLoop();
    }

    private function startHarness(): VirtualTuiHarness
    {
        $harness = new VirtualTuiHarness(sessionId: 'ctrl-c-interceptor');
        $state = new TuiSessionState('ctrl-c-interceptor');
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new CtrlCInputInterceptor())->register($context);
        $harness->startInputLoop();

        return $harness;
    }

    /**
     * @return array<string, string>
     */
    private function statusEntries(VirtualTuiHarness $harness): array
    {
        $ref = new \ReflectionProperty($harness->screen(), 'statusEntries');

        /* @var array<string, string> */
        return $ref->getValue($harness->screen());
    }

    private function currentScreenText(VirtualTuiHarness $harness): string
    {
        $buffer = new ScreenBuffer(
            width: $harness->terminal()->getColumns(),
            height: $harness->terminal()->getRows(),
        );
        $buffer->write($harness->terminal()->getOutput());

        return $buffer->getScreen();
    }
}
