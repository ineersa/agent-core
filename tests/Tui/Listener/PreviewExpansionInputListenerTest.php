<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Command\Hotkey\HotkeyRegistry;
use Ineersa\Tui\Listener\AppHotkeyRegistrar;
use Ineersa\Tui\Listener\PreviewExpansionInputListener;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\HotkeyTableRenderer;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use Ineersa\Tui\Transcript\TranscriptGlyphs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis: Ctrl+O (\x0f) through the real TUI input loop toggles
 * session-local preview expansion and re-renders previewable tool bodies.
 */
#[CoversClass(PreviewExpansionInputListener::class)]
final class PreviewExpansionInputListenerTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    #[Test]
    public function ctrlOTogglesLongToolExchangePreviewOnRealInputPath(): void
    {
        $displayConfig = new TranscriptDisplayConfig(
            toolResultPreviewLines: 2,
            diffPreviewLines: 2,
        );
        $displayState = new TranscriptDisplayState(previewableBlocksExpanded: false);
        $harness = new VirtualTuiHarness(
            sessionId: 'preview-toggle-session',
            displayConfig: $displayConfig,
            displayState: $displayState,
        );

        $state = new TuiSessionState('preview-toggle-session');
        $state->transcriptDisplayConfig = $displayConfig;
        $state->transcriptDisplayState = $displayState;

        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new PreviewExpansionInputListener())->register($context);
        $harness->startInputLoop();

        $resultLines = [];
        for ($i = 0; $i < 12; ++$i) {
            $resultLines[] = 'deep_line_'.$i;
        }
        $resultBody = implode("\n", $resultLines);

        $state->transcript = [
            new TranscriptBlock(
                id: 'tc-bash',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: 'preview-toggle-session',
                seq: 1,
                text: 'bash',
                meta: [
                    'tool_call_id' => 'call-preview',
                    'tool_name' => 'bash',
                    'arguments' => ['command' => 'echo preview'],
                ],
            ),
            new TranscriptBlock(
                id: 'tr-bash',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: 'preview-toggle-session',
                seq: 2,
                text: 'bash',
                meta: [
                    'tool_call_id' => 'call-preview',
                    'tool_name' => 'bash',
                    'result' => $resultBody,
                    'is_error' => false,
                ],
            ),
        ];
        $harness->screen()->setTranscriptBlocks($state->transcript);
        $harness->screen()->setWorkingVisible(false);

        $collapsed = $harness->plainScreenText();
        $this->assertStringContainsString('deep_line_0', $collapsed);
        $this->assertStringNotContainsString('deep_line_11', $collapsed);
        $this->assertStringContainsString('more line', $collapsed);

        $harness->sendInput("\x0f");

        $this->assertTrue($displayState->previewableBlocksExpanded);
        $expanded = $harness->plainScreenText();
        $this->assertStringContainsString('deep_line_11', $expanded);
        $this->assertStringNotContainsString('more line', $expanded);

        $harness->sendInput("\x0f");

        $this->assertFalse($displayState->previewableBlocksExpanded);
        $collapsedAgain = $harness->plainScreenText();
        $this->assertStringNotContainsString('deep_line_11', $collapsedAgain);
        $this->assertStringContainsString('more line', $collapsedAgain);
    }

    #[Test]
    public function ctrlODoesNotChangeUserAssistantBlockText(): void
    {
        $displayState = new TranscriptDisplayState(previewableBlocksExpanded: false);
        $harness = new VirtualTuiHarness(
            sessionId: 'preview-stable-session',
            displayState: $displayState,
        );
        $state = new TuiSessionState('preview-stable-session');
        $state->transcriptDisplayState = $displayState;

        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new PreviewExpansionInputListener())->register($context);
        $harness->startInputLoop();

        $state->transcript = [
            new TranscriptBlock(
                id: 'u-1',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'preview-stable-session',
                seq: 1,
                text: 'USER_STABLE_MARKER',
            ),
            new TranscriptBlock(
                id: 'a-1',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: 'preview-stable-session',
                seq: 2,
                text: 'ASSISTANT_STABLE_MARKER',
            ),
        ];
        $harness->screen()->setTranscriptBlocks($state->transcript);
        $harness->screen()->setWorkingVisible(false);

        $before = $harness->plainScreenText();
        $harness->sendInput("\x0f");
        $after = $harness->plainScreenText();

        $this->assertStringContainsString('USER_STABLE_MARKER', $before);
        $this->assertStringContainsString('ASSISTANT_STABLE_MARKER', $before);
        $this->assertStringContainsString('USER_STABLE_MARKER', $after);
        $this->assertStringContainsString('ASSISTANT_STABLE_MARKER', $after);
        $this->assertStringContainsString(TranscriptGlyphs::GLYPH_USER_MESSAGE, $after);
        $this->assertStringContainsString(TranscriptGlyphs::GLYPH_ASSISTANT_MESSAGE, $after);
    }

    #[Test]
    public function appHotkeyRegistrarListsCtrlOForHotkeyCatalog(): void
    {
        $harness = new VirtualTuiHarness();
        $state = new TuiSessionState('hotkey-session');
        $hotkeyRegistry = new HotkeyRegistry();

        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new AppHotkeyRegistrar($hotkeyRegistry))->register($context);

        $groups = $hotkeyRegistry->grouped();
        $found = false;
        foreach ($groups['Global'] ?? [] as $binding) {
            if (\in_array('ctrl+o', $binding->keys, true)) {
                $found = true;
                $this->assertStringContainsString('preview', strtolower($binding->action));
                break;
            }
        }
        $this->assertTrue($found, 'Hotkey catalog must include ctrl+o binding');

        $renderer = new HotkeyTableRenderer();
        $styled = $renderer->render(
            array_map(
                static fn (array $bindings): array => array_map(
                    static fn ($b): array => [
                        'keys' => $b->keys,
                        'action' => $b->action,
                        'description' => $b->description,
                    ],
                    $bindings,
                ),
                $groups,
            ),
            $harness->screen()->theme(),
            '',
        );
        $this->assertStringContainsString('Ctrl+O', $styled);
    }
}
