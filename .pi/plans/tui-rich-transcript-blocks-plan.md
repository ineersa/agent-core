# Rich Symfony TUI transcript blocks plan

Date: 2026-05-22

## Purpose

Replace the current flat transcript line renderer with Symfony TUI-native transcript blocks/cards that use existing vendor widgets and styling primitives wherever possible.

The goal is a nicer history/transcript window with:

- Markdown-rendered user, assistant, and visible thinking content.
- Thinking controlled by global config only in v1.
- Hidden thinking represented by a small placeholder: `⋯ Thinking`.
- Styled block/card shells for tools, results, errors, and approvals.
- Small previews for large tool outputs/diffs, with a live-only global expand/collapse keybind first.
- Data/state shape that can support per-block expansion later without requiring that UX now.

Non-goals for the first implementation:

- Mouse click-to-expand.
- Per-block keyboard selection/navigation.
- Persisting the `Ctrl+O` expand/collapse state.
- Reimplementing Markdown, borders, layout, or syntax highlighting outside Symfony TUI/vendor packages.
- Refactoring `ChatScreen` to a Symfony `ContainerWidget` layout.

## Confirmed design decisions

1. Keep the existing `ChatScreen -> LiveTextWidget -> TuiWidget::render(): string[]` bridge for v1.
2. Add a `SymfonyTuiWidgetRenderer` adapter as the only normal place that directly uses Symfony TUI `Renderer` for transcript internals.
3. The adapter should render via Symfony `Renderer` plus a root `ContainerWidget`, not by calling leaf widgets directly.
4. `TranscriptBlockWidget::render()` should build one Symfony widget tree for the whole transcript and render it once per transcript render.
5. Use `MarkdownWidget` for:
   - `UserMessage`
   - `AssistantMessage`
   - visible `AssistantThinking`
6. SYSTEM-03 preloaded `<skill name="..." location="...">` context blocks should render as compact SKILL/context blocks, not as read-tool output and not as ordinary user Markdown.
7. Do not use `MarkdownWidget` for tool results, tool calls, system/progress/error/question/approval/cancelled blocks in v1.
8. Thinking visibility is config-only in v1; no mutable thinking visibility in `TranscriptDisplayState`.
9. Visible thinking is full Markdown, styled dim/italic using thinking-specific styling.
10. Hidden thinking renders one small placeholder per thinking block: `⋯ Thinking`.
11. `Ctrl+O` toggles previewable blocks only and is live/session-only.
12. `Ctrl+O` must not affect user, assistant, thinking, system, error, progress, question, approval, cancelled, or tool-call blocks.
13. V1 previewable scope is limited to:
    - normal `ToolResult` previews
    - diff-rendered `ToolResult` previews
14. Diffs are a rendering classification of existing `ToolResult` blocks, not a new `TranscriptBlockKindEnum::Diff` in v1.
15. Diff rendering should be used for edit/write tool outputs only.
16. Hatfield config and TUI rendering config stay separated:
    - `Ineersa\CodingAgent\Config\TuiTranscriptConfig`
    - `Ineersa\Tui\Transcript\TranscriptDisplayConfig`
    - mapper/adapter between them at the TUI application boundary.
17. Update `depfile.yaml` to allow Symfony TUI dependency in the `TuiTranscript` layer.
18. `Ctrl+O` handling is listener-owned, matching existing TUI listener registrar style.
19. Tool call arguments should be rendered visibly; do not omit them. Use fenced YAML in the tool-call card for v1.
20. Card density is intentionally adjustable after real usage; start compact/subtle rather than over-designing borders now.

## Scouting notes

### Current agent-core state

Relevant files:

- `src/CodingAgent/Runtime/Projection/TranscriptBlock.php`
- `src/CodingAgent/Runtime/Projection/TranscriptBlockKindEnum.php`
- `src/CodingAgent/Runtime/ProjectionPipeline/AssistantStreamProjectionSubscriber.php`
- `src/CodingAgent/Runtime/ProjectionPipeline/ToolProjectionSubscriber.php`
- `src/Tui/Transcript/TranscriptBlockWidget.php`
- `src/Tui/Transcript/TranscriptBlockRenderer.php`
- `src/Tui/Screen/ChatScreen.php`
- `src/Tui/Application/InteractiveMode.php`
- `src/Tui/Runtime/TuiSessionState.php`
- `src/Tui/Theme/ThemeColorEnum.php`
- `config/hatfield.defaults.yaml`
- `.hatfield/settings.yaml`
- `docs/settings.md`
- `depfile.yaml`

Current transcript flow:

```text
RuntimeEventPoller
  -> TranscriptProjector
  -> TranscriptBlock[]
  -> ChatScreen::setTranscriptBlocks()
  -> TranscriptBlockWidget
  -> TranscriptBlockRenderer
  -> string[] lines
```

`TranscriptBlock` already has:

```php
public array $meta = [];
public bool $collapsed = false;
```

But `collapsed` is currently ignored by `TranscriptBlockRenderer`. Thinking blocks are also hardcoded as collapsed in `AssistantStreamProjectionSubscriber`, which should stop being the display default. Projection should not decide local UI display expansion.

Existing Hatfield TUI settings are only:

```yaml
tui:
    theme: cyberpunk
    theme_paths: [...]
```

There is no existing `tui.transcript.*` setting. `.pi/settings.json` has `hideThinkingBlock`, but that is pi-parent configuration and is not consumed by agent-core.

### pi-mono references

Useful references in `/home/ineersa/claw/pi-mono`:

- `packages/tui/src/tui.ts`
- `packages/tui/src/components/box.ts`
- `packages/tui/src/components/text.ts`
- `packages/tui/src/components/markdown.ts`
- `packages/coding-agent/src/modes/interactive/components/tool-execution.ts`
- `packages/coding-agent/src/modes/interactive/components/bash-execution.ts`
- `packages/coding-agent/src/modes/interactive/components/diff.ts`
- `packages/coding-agent/src/modes/interactive/interactive-mode.ts`

Pi's useful ideas:

- Transcript/history is a container of message/tool components, not flat role-prefixed lines.
- Tool definitions can self-render calls/results.
- Blocks can implement a simple expandable interface.
- Diff rendering is a dedicated component.

### opencode references

Useful references in `/home/ineersa/claw/opencode`:

- `packages/opencode/src/cli/cmd/tui/routes/session/index.tsx`
- `packages/opencode/src/cli/cmd/tui/context/kv.tsx`
- `packages/opencode/src/cli/cmd/tui/app.tsx`

opencode uses per-block local state for expandable blocks:

```tsx
const [expanded, setExpanded] = createSignal(false)
```

Tool output preview pattern:

```tsx
if (expanded() || !overflow()) return output()
return [...lines().slice(0, maxLines), "…"].join("\n")
```

Click handling is block-local and guarded against text selection:

```tsx
onMouseUp={() => {
  if (renderer.getSelection()?.getSelectedText()) return
  props.onClick?.()
}}
```

Thinking visibility is global in opencode. In agent-core v1, thinking visibility is also global, but config-only.

### Symfony TUI/vendor capabilities

Useful vendor classes:

- `Symfony\Component\Tui\Widget\MarkdownWidget`
- `Symfony\Component\Tui\Widget\TextWidget`
- `Symfony\Component\Tui\Widget\ContainerWidget`
- `Symfony\Component\Tui\Style\Style`
- `Symfony\Component\Tui\Style\Border`
- `Symfony\Component\Tui\Style\BorderPattern`
- `Symfony\Component\Tui\Render\Renderer`
- `Symfony\Component\Tui\Render\WidgetRect`
- `Symfony\Component\Tui\Render\PositionTracker`
- `Symfony\Component\Tui\Event\InputEvent`
- `SebastianBergmann\Diff\Differ`
- `SebastianBergmann\Diff\Parser`
- `SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder`

`MarkdownWidget` already supports CommonMark/GFM, headings, emphasis, code, blockquotes, links, tables, and syntax highlighting through `tempest/highlight`.

`Style`/`ContainerWidget` already provide border, padding, background, direction, gap, bold/dim/italic/underline/reverse, and layout chrome. Use this rather than rendering box borders by hand.

Symfony TUI currently does not expose a convenient public `onClick` widget API. It does have raw `InputEvent`, widget position tracking, and mouse parsing in the input layer, but mouse-to-widget hit testing should be treated as a later enhancement.

## Target UX

### Thinking

- Thinking is controlled globally by config only in v1.
- Default should be visible.
- Thinking content should render as Markdown when visible.
- Visible thinking should be visually subdued, e.g. dim/italic foreground, subtle border/background.
- Hidden thinking should not show content, but should show one placeholder per thinking block:

```text
⋯ Thinking
```

Confirmed settings:

```yaml
tui:
    transcript:
        thinking:
            visible: true
            style: dim_italic
```

Avoid a per-thinking-block expand/collapse UX initially. Thinking is not previewable and is not affected by `Ctrl+O`.

### Tools and outputs

- Tool calls/results should render as cards.
- Tool call card header should include tool name and status.
- Tool arguments should render visibly; unlike Pi's current behavior, they must not be omitted.
- Tool arguments should render as fenced YAML in the tool-call card for v1.
- Tool output/result should render as preview by default when long.
- `Ctrl+O` should toggle all previewable blocks between preview and expanded modes.
- `Ctrl+O` is live/session-only and must not write settings or session metadata.
- Errors should be generous/full by default; do not hide useful failures behind tiny previews.

Confirmed settings:

```yaml
tui:
    transcript:
        previews:
            expanded_by_default: false
            tool_result_lines: 8
            diff_lines: 20
```

### Assistant/user text

- Render through `MarkdownWidget`.
- Assistant and user messages are full by default.
- User blocks can be visually distinct but should not be too heavy.
- User and assistant messages are not affected by `Ctrl+O`.

### Diffs

- Diffs are detected/rendered from existing `ToolResult` blocks, not represented as a new transcript block kind in v1.
- Initial diff classification scope: edit/write tool outputs only.
- Render diff output in a dedicated Symfony TUI-aware widget/card.
- Use existing semantic colors from `ThemeColorEnum`, such as diff added/removed/context tokens, or add missing tokens as needed.
- First version can render unified diff lines with colors.
- Later version can add side-by-side view or inline word-level highlights.

## Target architecture

Keep `ChatScreen` and project-level widgets stable:

```text
ChatScreen
  -> LiveTextWidget producer closure
  -> TuiWidget::render(TuiRenderContext): string[]
  -> TranscriptBlockWidget
```

Inside `TranscriptBlockWidget`, build one Symfony widget tree for the whole transcript:

```text
TranscriptBlockWidget::render()
  -> TranscriptBlockCardFactory creates Symfony widgets per block
  -> root ContainerWidget for the complete transcript
  -> SymfonyTuiWidgetRenderer renders root once
  -> string[] lines returned to LiveTextWidget
```

Proposed shape:

```text
TranscriptBlockWidget
  -> TranscriptBlockCardFactory
      -> ContainerWidget/TextWidget/MarkdownWidget/DiffWidget per block
  -> SymfonyTuiWidgetRenderer
      -> Symfony\Component\Tui\Render\Renderer
      -> root ContainerWidget
```

Potential classes:

- `src/CodingAgent/Config/TuiTranscriptConfig.php`
- `src/Tui/Transcript/TranscriptDisplayConfig.php`
- `src/Tui/Transcript/TranscriptDisplayConfigMapper.php`
- `src/Tui/Transcript/TranscriptDisplayState.php`
- `src/Tui/Transcript/SymfonyTuiWidgetRenderer.php`
- `src/Tui/Transcript/TranscriptBlockCardFactory.php`
- `src/Tui/Transcript/TranscriptPreviewService.php`
- `src/Tui/Transcript/TranscriptDiffClassifier.php`
- `src/Tui/Transcript/TranscriptDiffBlockWidget.php`
- `src/Tui/Transcript/PreviewExpansionInputListener.php` or similarly explicit listener registrar name

Use Symfony TUI widgets internally. Keep project-level `TuiWidget` only as the bridge required by `ChatScreen`/`LiveTextWidget`.

## Adapter rendering model

`SymfonyTuiWidgetRenderer` should isolate direct use of Symfony TUI rendering APIs:

```php
final readonly class SymfonyTuiWidgetRenderer
{
    public function __construct(
        private Renderer $renderer = new Renderer(),
    ) {
    }

    /** @return list<string> */
    public function render(ContainerWidget $root, TuiRenderContext $context): array
    {
        return $this->renderer->render(
            root: $root,
            columns: max(1, $context->terminalWidth),
            rows: max(1, $context->terminalHeight),
        );
    }
}
```

Notes:

- Prefer rendering one root transcript `ContainerWidget`, not one root per transcript block.
- Use Symfony `Renderer` as the normal path so style cascade, layout, padding, borders, and chrome are applied consistently.
- Do not call `ContainerWidget::render()` directly; Symfony `Renderer` owns container rendering.
- If future click hit-testing is added, this adapter is the natural place to expose or coordinate widget rect tracking.

## Display config and state model

Introduce display config separately from canonical transcript projection.

TUI-local immutable rendering config:

```php
final readonly class TranscriptDisplayConfig
{
    public function __construct(
        public bool $thinkingVisible = true,
        public string $thinkingStyle = 'dim_italic',
        public bool $previewsExpandedByDefault = false,
        public int $toolResultPreviewLines = 8,
        public int $diffPreviewLines = 20,
    ) {
    }
}
```

Hatfield config DTO lives outside TUI:

```php
namespace Ineersa\CodingAgent\Config;

final readonly class TuiTranscriptConfig
{
    public function __construct(
        public bool $thinkingVisible = true,
        public string $thinkingStyle = 'dim_italic',
        public bool $previewsExpandedByDefault = false,
        public int $toolResultPreviewLines = 8,
        public int $diffPreviewLines = 20,
    ) {
    }
}
```

Map from Hatfield config to TUI config at the application boundary, e.g. `InteractiveMode`, using a mapper/adapter so `Tui\Transcript` does not depend on `CodingAgent\Config`.

Mutable display state is live-only:

```php
final class TranscriptDisplayState
{
    public function __construct(
        public bool $previewableBlocksExpanded = false,
    ) {
    }
}
```

Startup initialization:

```php
$state->transcriptDisplayState = new TranscriptDisplayState(
    previewableBlocksExpanded: $displayConfig->previewsExpandedByDefault,
);
```

Runtime `Ctrl+O` toggles only `previewableBlocksExpanded`. It does not persist to Hatfield settings or session metadata.

No `thinkingVisible` state in v1. The renderer reads `TranscriptDisplayConfig::thinkingVisible` directly.

For future per-block expansion, add block-local overrides later. Do not add unused override arrays in v1 unless implementation pressure requires them.

## Settings model

Add nested TUI transcript config under the confirmed key names.

Defaults in `config/hatfield.defaults.yaml`:

```yaml
tui:
    theme: cyberpunk
    theme_paths:
        - '%project_dir%/config/themes'
        - '%project_dir%/.hatfield/themes'
        - '%home_dir%/.hatfield/themes'
    transcript:
        thinking:
            visible: true
            style: dim_italic
        previews:
            expanded_by_default: false
            tool_result_lines: 8
            diff_lines: 20
```

Config object changes:

- Add `src/CodingAgent/Config/TuiTranscriptConfig.php`.
- Add nested value object to existing TUI config.
- Wire parsing/defaults in `AppConfig`.
- Add TUI-local `TranscriptDisplayConfig`.
- Add mapper/adapter between Hatfield config and TUI display config.
- Document in `docs/settings.md`.
- Add example values/comments to `.hatfield/settings.yaml`.

Naming must follow project convention with explicit suffixes: use `TuiTranscriptConfig`, `TranscriptDisplayConfig`, `TranscriptDisplayState`, `TranscriptDisplayConfigMapper`, etc.

## Tracked task breakdown

Created TODO tasks are prefixed with `RENDER-` and should be done in this order unless explicitly parallelized below.

### Order and dependency graph

```text
RENDER-01 Transcript display config/state foundation
  ├─ RENDER-02 Symfony widget renderer adapter/root tree
  │   ├─ RENDER-03 Markdown user/assistant/thinking rendering
  │   ├─ RENDER-04 Tool cards, fenced YAML args, normal previews
  │   │   └─ RENDER-05 Edit/write diff classification/rendering
  │   └─ RENDER-06 Ctrl+O preview expansion listener
  └─ RENDER-07 Docs, snapshots, product-level validation (final, after all above)
```

### Parallelization notes

- `RENDER-01` is the root setup task and should land first.
- After `RENDER-01`, `RENDER-02` and listener plumbing for `RENDER-06` can start in parallel, but `RENDER-06` final acceptance needs previewable renderers from `RENDER-04`/`RENDER-05`.
- After `RENDER-02`, `RENDER-03` and `RENDER-04` can run in parallel.
- `RENDER-05` classifier/service work can start after `RENDER-01`/`RENDER-02`, but final integration is easiest after or alongside `RENDER-04` because it is a specialized `ToolResult` rendering path.
- `RENDER-07` is final-only and should run after `RENDER-01` through `RENDER-06` have landed.

### Task list

1. `RENDER-01`: Transcript display config, mapper, and live state foundation.
2. `RENDER-02`: Symfony TUI widget renderer adapter and root transcript tree.
3. `RENDER-03`: Markdown rendering for user, assistant, and thinking blocks.
4. `RENDER-04`: Tool cards with fenced YAML args and normal output previews.
5. `RENDER-05`: Edit/write `ToolResult` diff classification and rendering.
6. `RENDER-06`: `Ctrl+O` preview expansion listener.
7. `RENDER-07`: Docs, snapshots, real LLM agent rendering test, and product-level rich transcript validation.

## Implementation phases

### Phase 1: Config, mapper, and live state foundation

1. Add `TuiTranscriptConfig` with:
   - `thinkingVisible: bool = true`
   - `thinkingStyle: string = 'dim_italic'`
   - `previewsExpandedByDefault: bool = false`
   - `toolResultPreviewLines: int = 8`
   - `diffPreviewLines: int = 20`
2. Add defaults under `tui.transcript`.
3. Update `.hatfield/settings.yaml` and `docs/settings.md`.
4. Add `TranscriptDisplayConfig` in `src/Tui/Transcript/`.
5. Add mapper/adapter between `TuiTranscriptConfig` and `TranscriptDisplayConfig` at the TUI application boundary.
6. Add `TranscriptDisplayState` with only `previewableBlocksExpanded`.
7. Add display state to `TuiSessionState` and initialize from `previewsExpandedByDefault` during TUI startup.
8. Stop hardcoding thinking `collapsed: true` as the display default. Projection should not decide local UI expansion defaults.
9. Update `depfile.yaml` so `TuiTranscript` may depend on Symfony TUI.

### Phase 2: Symfony TUI Markdown/card rendering

1. Add `SymfonyTuiWidgetRenderer` adapter around Symfony `Renderer`.
2. Change `TranscriptBlockWidget::render()` to build one root `ContainerWidget` for the whole transcript and render it once through the adapter.
3. Create a card/factory layer that uses Symfony TUI primitives:
   - `ContainerWidget`
   - `MarkdownWidget`
   - `TextWidget`
   - `Style`
   - `BorderPattern`
4. Render user, assistant, and visible thinking blocks through `MarkdownWidget`.
5. Render hidden thinking as one placeholder per thinking block: `⋯ Thinking`.
6. Apply subdued thinking style through Symfony `Style` rather than manually injecting ANSI everywhere where possible.
7. Preserve ANSI width safety through Symfony renderer instead of manual wrapping where possible.
8. Keep `ChatScreen` and `LiveTextWidget` bridge unchanged.

### Phase 3: Tool cards, previews, and Ctrl+O

1. Render tool call/result blocks as cards with semantic headers.
2. Render tool call arguments as fenced YAML in the tool-call card.
3. Implement line previewing for long normal tool outputs.
4. Add diff classification for edit/write `ToolResult` outputs.
5. Implement line previewing for diff-rendered tool results.
6. Add global `previewableBlocksExpanded` handling for tool result/diff previews only.
7. Add listener-owned `Ctrl+O` keybind to toggle global preview expansion.
8. Ensure user/assistant/thinking/system/error/tool-call blocks are unaffected by `Ctrl+O`.
9. Ensure streaming output remains stable and avoids large layout churn.

### Phase 4: Diff rendering

1. Add a dedicated diff rendering widget/service for diff-classified `ToolResult` blocks.
2. Use `sebastian/diff` for generation/parsing when source/target text is available.
3. Color unified diff lines using theme tokens.
4. Apply `diff_lines: 20` preview limit for large diffs when global preview mode is collapsed.
5. Keep `TranscriptBlockKindEnum` unchanged; do not add `Diff` in v1.
6. Consider side-by-side or inline word-level highlighting later.

### Phase 5: Future per-block expansion

Keep the model conceptually ready, but do not build this for v1.

Later options:

- Keyboard selection within transcript, then `Enter`/`Space` toggles selected block.
- Mouse support by parsing `InputEvent` mouse sequences and mapping coordinates to block `WidgetRect`s.
- A Symfony TUI extension/contribution that exposes public click events for widgets.

Do not build custom mouse hit-testing until the Symfony TUI public API is confirmed insufficient for the target UX.

## Validation plan

Because this touches TUI runtime/transcript rendering, unit tests are not enough.

Required validation after implementation:

- `castor test --filter=<focused transcript/config tests>` during development.
- `castor phpstan src/Tui src/CodingAgent/Config src/CodingAgent/Runtime/ProjectionPipeline` for focused static checks.
- Product-level TUI validation before calling done:
  - `castor run:agent-test`, or
  - `castor test:tui`
- Create or update a real agent/real LLM validation test for rich transcript rendering, at minimum covering assistant message blocks and thinking blocks.

The product-level run must exercise:

1. Start TUI.
2. Submit a prompt that produces assistant Markdown.
3. Produce or simulate thinking content.
4. In the real agent/real LLM validation path, verify at minimum assistant message block rendering and thinking block rendering.
5. Verify visible thinking renders as Markdown with dim/italic styling.
6. Verify `thinking.visible: false` renders one `⋯ Thinking` placeholder per hidden thinking block.
7. Produce tool call/result output with enough lines to preview.
8. Verify tool call arguments are visible as fenced YAML.
9. Produce or simulate edit/write output that is classified as a diff-rendered `ToolResult`.
10. Toggle `Ctrl+O` and verify only previewable tool result/diff blocks expand/collapse.
11. Verify user, assistant, thinking, system, error, and tool-call blocks are unaffected by `Ctrl+O`.
12. Capture/report snapshot and session artifacts on failure:
    - `.hatfield/sessions/<id>/events.jsonl`
    - `.hatfield/sessions/<id>/runtime-events.jsonl`
    - `.hatfield/sessions/<id>/transcript.jsonl`

## Remaining open questions

1. Exact conflict check for `Ctrl+O` in terminal input handling.
2. Exact set of accepted `thinking.style` values beyond initial `dim_italic`.
3. Card density after real usage: full border vs left border vs minimal separators. Start compact/subtle and adjust based on snapshots.
