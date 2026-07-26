<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;

/**
 * Production transcript region as a mounted Symfony ContainerWidget subtree.
 *
 * Replaces the ChatScreen LiveTextWidget → TranscriptBlockWidget → offscreen Renderer path
 * for the transcript slot. Children are first-class widgets attached to the live WidgetContext,
 * so MarkdownWidget sub-element styles resolve through the active Tui stylesheet.
 *
 * Reconciliation keeps stable visual-node wrappers keyed by presentation identity. Ordinary
 * updates mutate or replace content inside wrappers; the outer container uses granular
 * append/remove. Full clear()+ordered add is reserved for non-local insert/reorder that
 * Symfony v8.1 ContainerWidget cannot express (append-only public API).
 *
 * Tool-call → tool-result pairing uses a stable exchange key so result arrival is a wrapper
 * content replace, not remove/reinsert.
 *
 * Streaming Markdown uses stock {@see MarkdownWidget} and preserves instance identity via
 * setText()/setStyle(). No height-reservation policy is applied here.
 */
final class TranscriptMountedWidget extends ContainerWidget
{
    private const string WELCOME_KEY = '__welcome__';
    private const string VISUAL_KIND_MARKDOWN = 'markdown';
    private const string VISUAL_KIND_TOOL_EXCHANGE = 'tool_exchange';
    private const string VISUAL_KIND_GENERIC = 'generic';
    private const string VISUAL_KIND_SEPARATOR = 'separator';
    private const string VISUAL_KIND_WELCOME = 'welcome';

    private const string OUTER_RESYNC_REASON_RELATIVE_ORDER = 'relative_order_changed';
    private const string OUTER_RESYNC_REASON_NON_TAIL_INSERTION = 'non_tail_insertion';

    /** @var list<TranscriptBlock> */
    private array $blocks = [];

    private readonly TranscriptBlockWidgetFactory $factory;

    /**
     * Stable visual nodes currently mounted (outer children are wrappers).
     *
     * @var array<string, array{
     *     key: string,
     *     kind: string,
     *     fingerprint: string,
     *     wrapper: TranscriptVisualNodeWidget,
     *     streaming: bool
     * }>
     */
    private array $nodes = [];

    /** @var list<string> */
    private array $nodeOrder = [];

    public function __construct(
        private readonly TuiTheme $theme,
        TranscriptDisplayConfig $displayConfig = new TranscriptDisplayConfig(),
        TranscriptDisplayState $displayState = new TranscriptDisplayState(),
    ) {
        $this->factory = new TranscriptBlockWidgetFactory(
            subagentRenderer: new SubagentResultRenderer(
                displayConfig: $displayConfig,
                displayState: $displayState,
            ),
            displayConfig: $displayConfig,
            displayState: $displayState,
        );
        $this->reconcile();
    }

    /** @return list<TranscriptBlock> */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * Replace the canonical transcript snapshot and reconcile mounted children.
     *
     * @param list<TranscriptBlock> $blocks
     */
    public function setBlocks(array $blocks): void
    {
        $this->blocks = array_values($blocks);
        $this->reconcile();
    }

    private function reconcile(): void
    {
        $desired = $this->buildDesiredVisualItems();

        $desiredKeys = [];
        $desiredByKey = [];
        foreach ($desired as $item) {
            $desiredKeys[] = $item['key'];
            $desiredByKey[$item['key']] = $item;
        }

        $previousOrder = $this->nodeOrder;
        $previousNodes = $this->nodes;
        $outerResyncReason = $this->detectOuterResyncReason($previousOrder, $desiredKeys);

        if (null !== $outerResyncReason) {
            // Genuine non-local structure change that ContainerWidget cannot splice in-place.
            $this->performOuterResync($desired, $desiredKeys, $previousNodes);

            return;
        }

        // 1) Direct-remove obsolete wrappers while preserving survivors.
        foreach ($previousOrder as $existingKey) {
            if (isset($desiredByKey[$existingKey])) {
                continue;
            }
            $node = $previousNodes[$existingKey] ?? null;
            if (null !== $node) {
                $this->remove($node['wrapper']);
                unset($this->nodes[$existingKey]);
            }
        }
        $this->nodeOrder = array_values(array_filter(
            $this->nodeOrder,
            static fn (string $key): bool => isset($desiredByKey[$key]),
        ));

        // 2) Update or create wrappers; new keys are pure tail appends after removals.
        // After step 1, $this->nodes only holds survivors still present in desired order.
        $nextNodes = [];
        $nextOrder = [];
        foreach ($desired as $item) {
            $key = $item['key'];
            $existing = $this->nodes[$key] ?? null;
            $fingerprint = $item['fingerprint'];
            $streaming = $item['streaming'];

            if (null === $existing) {
                $wrapper = new TranscriptVisualNodeWidget();
                $wrapper->setContent($this->buildWidgetForItem($item));
                $this->add($wrapper);
                $nextNodes[$key] = [
                    'key' => $key,
                    'kind' => $item['kind'],
                    'fingerprint' => $fingerprint,
                    'wrapper' => $wrapper,
                    'streaming' => $streaming,
                ];
                $nextOrder[] = $key;
                continue;
            }

            $wrapper = $existing['wrapper'];
            $this->applyItemToExistingWrapper($wrapper, $existing, $item);

            $nextNodes[$key] = [
                'key' => $key,
                'kind' => $item['kind'],
                'fingerprint' => $fingerprint,
                'wrapper' => $wrapper,
                'streaming' => $streaming,
            ];
            $nextOrder[] = $key;
        }

        $this->nodes = $nextNodes;
        $this->nodeOrder = $nextOrder;
    }

    /**
     * Detect whether Symfony's append/remove API can express the order transition.
     *
     * Outer clear()+add is required only when:
     * - relative order of surviving keys changes, or
     * - a new key is inserted before an already-mounted survivor (non-tail insertion).
     *
     * Empty bootstrap uses granular tail-append instead of outer resync.
     *
     * @param list<string> $previousOrder
     * @param list<string> $desiredKeys
     */
    private function detectOuterResyncReason(array $previousOrder, array $desiredKeys): ?string
    {
        if ([] === $previousOrder) {
            // First mount / empty → pure append path.
            return null;
        }

        // buildDesiredVisualItems() always emits at least the welcome node, so desiredKeys is never empty.

        $previousSet = array_fill_keys($previousOrder, true);
        $desiredSet = array_fill_keys($desiredKeys, true);

        $previousSurviving = [];
        foreach ($previousOrder as $key) {
            if (isset($desiredSet[$key])) {
                $previousSurviving[] = $key;
            }
        }

        $desiredSurviving = [];
        foreach ($desiredKeys as $key) {
            if (isset($previousSet[$key])) {
                $desiredSurviving[] = $key;
            }
        }

        if ($previousSurviving !== $desiredSurviving) {
            return self::OUTER_RESYNC_REASON_RELATIVE_ORDER;
        }

        $seenNew = false;
        foreach ($desiredKeys as $key) {
            $isNew = !isset($previousSet[$key]);
            if ($isNew) {
                $seenNew = true;
                continue;
            }
            if ($seenNew) {
                return self::OUTER_RESYNC_REASON_NON_TAIL_INSERTION;
            }
        }

        return null;
    }

    /**
     * @param list<array{
     *     key: string,
     *     kind: string,
     *     fingerprint: string,
     *     streaming: bool,
     *     primary: ?TranscriptBlock,
     *     secondary: ?TranscriptBlock
     * }> $desired
     * @param list<string> $desiredKeys
     * @param array<string, array{
     *     key: string,
     *     kind: string,
     *     fingerprint: string,
     *     wrapper: TranscriptVisualNodeWidget,
     *     streaming: bool
     * }> $previousNodes
     */
    private function performOuterResync(array $desired, array $desiredKeys, array $previousNodes): void
    {
        $nextNodes = [];
        $nextOrder = [];
        foreach ($desired as $item) {
            $key = $item['key'];
            $existing = $previousNodes[$key] ?? null;
            $fingerprint = $item['fingerprint'];
            $streaming = $item['streaming'];

            if (null === $existing) {
                $wrapper = new TranscriptVisualNodeWidget();
                $wrapper->setContent($this->buildWidgetForItem($item));
            } else {
                $wrapper = $existing['wrapper'];
                $this->applyItemToExistingWrapper($wrapper, $existing, $item);
            }

            $nextNodes[$key] = [
                'key' => $key,
                'kind' => $item['kind'],
                'fingerprint' => $fingerprint,
                'wrapper' => $wrapper,
                'streaming' => $streaming,
            ];
            $nextOrder[] = $key;
        }

        $this->clear();
        foreach ($nextOrder as $key) {
            $this->add($nextNodes[$key]['wrapper']);
        }

        $this->nodes = $nextNodes;
        $this->nodeOrder = $nextOrder;
    }

    /**
     * Update an already-mounted wrapper for a desired visual item.
     *
     * Shared by granular reconcile and outer resync so Markdown setText/setStyle and
     * content-replace policy stay in one place.
     *
     * @param array{
     *     key: string,
     *     kind: string,
     *     fingerprint: string,
     *     wrapper: TranscriptVisualNodeWidget,
     *     streaming: bool
     * } $existing
     * @param array{
     *     key: string,
     *     kind: string,
     *     fingerprint: string,
     *     streaming: bool,
     *     primary: ?TranscriptBlock,
     *     secondary: ?TranscriptBlock
     * } $item
     */
    private function applyItemToExistingWrapper(
        TranscriptVisualNodeWidget $wrapper,
        array $existing,
        array $item,
    ): void {
        if ($existing['fingerprint'] === $item['fingerprint'] && $existing['kind'] === $item['kind']) {
            return;
        }

        if (
            self::VISUAL_KIND_MARKDOWN === $item['kind']
            && null !== $item['primary']
            && $wrapper->content() instanceof MarkdownWidget
        ) {
            $this->updateMarkdownWidget($wrapper->content(), $item['primary']);

            return;
        }

        $wrapper->setContent($this->buildWidgetForItem($item));
    }

    /**
     * @return list<array{
     *     key: string,
     *     kind: string,
     *     fingerprint: string,
     *     streaming: bool,
     *     primary: ?TranscriptBlock,
     *     secondary: ?TranscriptBlock
     * }>
     */
    private function buildDesiredVisualItems(): array
    {
        if ([] === $this->blocks) {
            return [[
                'key' => self::WELCOME_KEY,
                'kind' => self::VISUAL_KIND_WELCOME,
                'fingerprint' => 'welcome:'.$this->theme->name(),
                'streaming' => false,
                'primary' => null,
                'secondary' => null,
            ]];
        }

        $toolResultsByCallId = $this->indexToolResultsByCallId($this->blocks);
        $consumedToolResultIds = [];
        $consumedToolCallIds = [];
        $items = [];
        $hasRenderedVisibleBlock = false;
        $envFingerprint = $this->renderEnvironmentFingerprint();
        $themeFingerprint = $this->themeFingerprint();

        $blockCount = \count($this->blocks);
        for ($index = 0; $index < $blockCount; ++$index) {
            $block = $this->blocks[$index];
            $nextBlock = $this->blocks[$index + 1] ?? null;

            if ($this->factory->isTranscriptWidgetSuppressed($block)) {
                continue;
            }
            if ($this->factory->shouldSuppressEmptyAssistantPlaceholder($block, $nextBlock)) {
                continue;
            }
            if (TranscriptBlockKindEnum::ToolResult === $block->kind
                && $this->factory->shouldSkipStandaloneToolResultInList($block, $consumedToolCallIds)) {
                continue;
            }

            if ($this->shouldInsertTurnSeparatorBefore($block, $hasRenderedVisibleBlock)) {
                $items[] = [
                    'key' => 'sep-before:'.$block->id,
                    'kind' => self::VISUAL_KIND_SEPARATOR,
                    'fingerprint' => 'sep:'.$themeFingerprint,
                    'streaming' => false,
                    'primary' => null,
                    'secondary' => null,
                ];
            }

            $matchedToolResult = null;
            if (TranscriptBlockKindEnum::ToolCall === $block->kind) {
                $matchedToolResult = $this->factory->findCombinableToolResultForCall(
                    $block,
                    $toolResultsByCallId,
                    $consumedToolResultIds,
                    $consumedToolCallIds,
                );
            }

            if (null !== $matchedToolResult) {
                $this->factory->markToolResultConsumedForExchange(
                    $matchedToolResult,
                    $consumedToolResultIds,
                    $consumedToolCallIds,
                );
                $key = $this->stableToolVisualKey($block);
                $items[] = [
                    'key' => $key,
                    'kind' => self::VISUAL_KIND_TOOL_EXCHANGE,
                    'fingerprint' => $this->blockFingerprint($block, $envFingerprint, $themeFingerprint, $matchedToolResult),
                    'streaming' => $block->streaming || $matchedToolResult->streaming,
                    'primary' => $block,
                    'secondary' => $matchedToolResult,
                ];
            } elseif (TranscriptBlockKindEnum::ToolCall === $block->kind) {
                // Pending tool call uses the same stable key as the eventual exchange so
                // result arrival becomes an in-wrapper content replace, not remove/reinsert.
                $key = $this->stableToolVisualKey($block);
                $items[] = [
                    'key' => $key,
                    'kind' => self::VISUAL_KIND_GENERIC,
                    'fingerprint' => $this->blockFingerprint($block, $envFingerprint, $themeFingerprint, null),
                    'streaming' => $block->streaming,
                    'primary' => $block,
                    'secondary' => null,
                ];
            } else {
                $kind = $this->isMarkdownVisual($block) ? self::VISUAL_KIND_MARKDOWN : self::VISUAL_KIND_GENERIC;
                $items[] = [
                    'key' => $block->id,
                    'kind' => $kind,
                    'fingerprint' => $this->blockFingerprint($block, $envFingerprint, $themeFingerprint, null),
                    'streaming' => $block->streaming,
                    'primary' => $block,
                    'secondary' => null,
                ];
            }

            $hasRenderedVisibleBlock = true;
        }

        if ([] === $items) {
            return [[
                'key' => self::WELCOME_KEY,
                'kind' => self::VISUAL_KIND_WELCOME,
                'fingerprint' => 'welcome:'.$this->theme->name(),
                'streaming' => false,
                'primary' => null,
                'secondary' => null,
            ]];
        }

        return $items;
    }

    /**
     * Stable presentation key for a tool call and its eventual paired exchange.
     *
     * Invariant: canonical projection writes one ToolCall block per non-empty
     * tool_call_id (`tool_call_<id>` via ToolProjectionSubscriber). Identity is
     * that id; block id is only a degenerate fallback if meta is empty.
     */
    private function stableToolVisualKey(TranscriptBlock $callBlock): string
    {
        $callId = $callBlock->meta['tool_call_id'] ?? null;
        if (\is_string($callId) && '' !== $callId) {
            return 'exchange:'.$callId;
        }

        return 'exchange:'.$callBlock->id;
    }

    /**
     * @param array{
     *     key: string,
     *     kind: string,
     *     fingerprint: string,
     *     streaming: bool,
     *     primary: ?TranscriptBlock,
     *     secondary: ?TranscriptBlock
     * } $item
     */
    private function buildWidgetForItem(array $item): AbstractWidget
    {
        return match ($item['kind']) {
            self::VISUAL_KIND_WELCOME => new WelcomeTranscriptWidget($this->theme),
            self::VISUAL_KIND_SEPARATOR => new TurnSeparatorWidget($this->theme),
            self::VISUAL_KIND_TOOL_EXCHANGE => $this->factory->buildToolExchangeWidget(
                $item['primary'] ?? throw new \LogicException('Tool exchange missing call block.'),
                $item['secondary'] ?? throw new \LogicException('Tool exchange missing result block.'),
                $this->theme,
            ),
            default => $this->factory->buildWidget(
                $item['primary'] ?? throw new \LogicException('Visual item missing primary block.'),
                $this->theme,
            ),
        };
    }

    private function updateMarkdownWidget(MarkdownWidget $widget, TranscriptBlock $block): void
    {
        // Rebuild via factory so glyph/prefix/style/thinking style stay consistent,
        // then copy resolved text/style onto the stable mounted instance.
        $fresh = $this->factory->buildWidget($block, $this->theme);
        if (!$fresh instanceof MarkdownWidget) {
            return;
        }

        $widget->setText($fresh->getText());
        $widget->setStyle($fresh->getStyle());
    }

    private function isMarkdownVisual(TranscriptBlock $block): bool
    {
        if (\in_array($block->kind, [
            TranscriptBlockKindEnum::UserMessage,
            TranscriptBlockKindEnum::AssistantMessage,
            TranscriptBlockKindEnum::AssistantThinking,
        ], true)) {
            return true;
        }

        return TranscriptBlockKindEnum::System === $block->kind
            && 'markdown' === ($block->meta['style'] ?? null);
    }

    /**
     * @param list<TranscriptBlock> $blocks
     *
     * @return array<string, list<TranscriptBlock>>
     */
    private function indexToolResultsByCallId(array $blocks): array
    {
        $index = [];
        foreach ($blocks as $block) {
            if (TranscriptBlockKindEnum::ToolResult !== $block->kind) {
                continue;
            }
            $callId = $block->meta['tool_call_id'] ?? null;
            if (!\is_string($callId) || '' === $callId) {
                continue;
            }
            $index[$callId][] = $block;
        }

        return $index;
    }

    private function shouldInsertTurnSeparatorBefore(TranscriptBlock $block, bool $hasRenderedVisibleBlock): bool
    {
        if (!$hasRenderedVisibleBlock) {
            return false;
        }

        return TranscriptBlockKindEnum::UserMessage === $block->kind;
    }

    private function blockFingerprint(
        TranscriptBlock $block,
        string $envFingerprint,
        string $themeFingerprint,
        ?TranscriptBlock $matchedToolResult,
    ): string {
        // Include full meta (including visible subagent elapsed/token/cost fields) so cards
        // that render those metrics refresh when the values change.
        $meta = $block->meta;
        ksort($meta);

        $parts = [
            $block->id,
            $block->kind->value,
            (string) $block->seq,
            $block->text,
            serialize($meta),
            $block->streaming ? '1' : '0',
            $themeFingerprint,
            $envFingerprint,
        ];

        if (null !== $matchedToolResult) {
            $resultMeta = $matchedToolResult->meta;
            ksort($resultMeta);
            $parts[] = 'exchange:'.$matchedToolResult->id;
            $parts[] = (string) $matchedToolResult->seq;
            $parts[] = $matchedToolResult->text;
            $parts[] = serialize($resultMeta);
            $parts[] = $matchedToolResult->streaming ? '1' : '0';
        }

        return hash('xxh128', implode("\x1e", $parts));
    }

    private function themeFingerprint(): string
    {
        $palette = $this->theme->getPalette();

        return hash('xxh128', $palette->name.serialize($palette->colors));
    }

    private function renderEnvironmentFingerprint(): string
    {
        $config = $this->factory->displayConfig();
        $state = $this->factory->displayState();

        return hash('xxh128', serialize([
            $config->thinkingVisible,
            $config->thinkingStyle,
            $config->previewsExpandedByDefault,
            $config->toolResultPreviewLines,
            $config->diffPreviewLines,
            $state->previewableBlocksExpanded,
        ]));
    }
}
